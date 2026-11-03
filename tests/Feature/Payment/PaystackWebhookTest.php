<?php

namespace Tests\Feature\Payment;

use App\Domains\Organization\Models\Business;
use App\Domains\Payment\Contracts\PaymentGateway;
use App\Domains\Payment\Models\Payment;
use App\Domains\Payment\Models\PaymentWebhookEvent;
use App\Domains\Payment\Services\PaymentConfirmationService;
use App\Domains\Payment\Services\SubscriptionPaymentFailureService;
use App\Domains\Payment\Services\SubscriptionRenewalService;
use App\Domains\Subscription\Models\Subscription;
use App\Domains\Subscription\Models\SubscriptionPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class PaystackWebhookTest extends TestCase
{
    use RefreshDatabase;

    private string $secret =
    'test-paystack-secret-key';

    private string $providerStatus = 'attention';

    private string $providerInvoiceStatus = 'attention';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.paystack.secret_key' =>
            $this->secret,
        ]);

        $gateway = Mockery::mock(PaymentGateway::class);
        $gateway->shouldReceive('fetchSubscription')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function (string $code): array {
                $subscription = Subscription::query()
                    ->with('plan')
                    ->where('provider_subscription_code', $code)
                    ->firstOrFail();

                return [
                    'success' => true,
                    'data' => [
                        'subscription_code' => $code,
                        'customer' => ['customer_code' => $subscription->provider_customer_code],
                        'amount' => (int) round((float) $subscription->plan->price * 100),
                        'plan' => ['currency' => $subscription->plan->currency],
                        'status' => $this->providerStatus,
                        'most_recent_invoice' => ['status' => $this->providerInvoiceStatus],
                    ],
                ];
            });

        $this->app->instance(PaymentGateway::class, $gateway);
    }

    /*
    |--------------------------------------------------------------------------
    | Signature validation
    |--------------------------------------------------------------------------
    */

    public function test_webhook_rejects_missing_signature(): void
    {
        $response = $this->postJson(
            '/api/webhooks/paystack',
            [
                'event' => 'charge.success',
                'data' => [
                    'reference' =>
                    'MERCHANTOS-TEST-001',
                ],
            ]
        );

        $response
            ->assertStatus(401)
            ->assertJson([
                'success' => false,
                'message' =>
                'Missing Paystack signature.',
            ]);
    }

    public function test_webhook_rejects_invalid_signature(): void
    {
        $payload = [
            'event' => 'charge.success',
            'data' => [
                'reference' =>
                'MERCHANTOS-TEST-002',
            ],
        ];

        $response = $this->call(
            'POST',
            '/api/webhooks/paystack',
            [],
            [],
            [],
            [
                'HTTP_X_PAYSTACK_SIGNATURE' =>
                'invalid-signature',

                'CONTENT_TYPE' =>
                'application/json',
            ],
            json_encode(
                $payload,
                JSON_UNESCAPED_SLASHES
            )
        );

        $response
            ->assertStatus(401)
            ->assertJson([
                'success' => false,
                'message' =>
                'Invalid Paystack signature.',
            ]);

        expect(PaymentWebhookEvent::query()->count())->toBe(0);

        $rawBody = '{"event":"charge.success","data":{"reference":"UNCHANGED-001"}}';
        $providerEventId = hash('sha256', 'paystack:' . $rawBody);
        $existing = PaymentWebhookEvent::create([
            'provider' => 'paystack',
            'provider_event_id' => $providerEventId,
            'event' => 'customer.updated',
            'reference' => 'ORIGINAL-REFERENCE',
            'status' => 'manual_review',
            'payload' => ['original' => true],
            'error_message' => 'Keep this audit record.',
        ]);

        $before = $existing->only(['event', 'reference', 'status', 'payload', 'error_message']);
        $this->postRawSignedWebhook($rawBody, 'invalid-signature')->assertStatus(401);

        expect($existing->fresh()->only(['event', 'reference', 'status', 'payload', 'error_message']))
            ->toBe($before)
            ->and(PaymentWebhookEvent::query()->count())->toBe(1);
    }

    public function test_webhook_event_identity_uses_the_exact_raw_request_body(): void
    {
        $rawBody = "{ \"event\" : \"customer.updated\", \"data\" : {\"customer_code\":\"CUS-RAW\"} }";

        $this->postRawSignedWebhook($rawBody)
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        $event = PaymentWebhookEvent::query()->firstOrFail();

        expect($event->provider)->toBe('paystack')
            ->and($event->provider_event_id)->toBe(hash('sha256', 'paystack:' . $rawBody))
            ->and($event->event)->toBe('customer.updated')
            ->and($event->status)->toBe('processed');
    }

    public function test_existing_processed_duplicate_keeps_original_payload_and_metadata(): void
    {
        $rawBody = json_encode([
            'event' => 'charge.success',
            'data' => ['reference' => 'ORIGINAL-PAYLOAD-001'],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $providerEventId = hash('sha256', 'paystack:' . $rawBody);
        $event = PaymentWebhookEvent::create([
            'provider' => 'paystack',
            'provider_event_id' => $providerEventId,
            'event' => 'original.event',
            'reference' => 'ORIGINAL-REFERENCE',
            'status' => 'processed',
            'payload' => ['retained' => ['sequence' => 7]],
            'error_message' => null,
            'processed_at' => now()->subMinute(),
        ]);

        $confirmationService = Mockery::mock(PaymentConfirmationService::class);
        $confirmationService->shouldReceive('confirm')->never();
        $this->app->instance(PaymentConfirmationService::class, $confirmationService);

        $this->postRawSignedWebhook($rawBody)
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        $event->refresh();
        expect($event->event)->toBe('original.event')
            ->and($event->reference)->toBe('ORIGINAL-REFERENCE')
            ->and($event->payload)->toBe(['retained' => ['sequence' => 7]])
            ->and($event->status)->toBe('processed')
            ->and(PaymentWebhookEvent::query()->count())->toBe(1);
    }

    public function test_existing_active_lease_is_acknowledged_without_reprocessing(): void
    {
        $payload = ['event' => 'charge.success', 'data' => ['reference' => 'ACTIVE-LEASE-001']];
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $event = PaymentWebhookEvent::create([
            'provider' => 'paystack',
            'provider_event_id' => hash('sha256', 'paystack:' . $body),
            'event' => 'charge.success',
            'reference' => 'ACTIVE-LEASE-001',
            'status' => 'processing',
            'payload' => $payload,
            'processing_token' => (string) Str::uuid(),
            'processing_started_at' => now(),
            'attempts' => 1,
        ]);

        $confirmationService = Mockery::mock(PaymentConfirmationService::class);
        $confirmationService->shouldReceive('confirm')->never();
        $this->app->instance(PaymentConfirmationService::class, $confirmationService);

        $this->postSignedWebhook($payload)
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        expect($event->fresh()->status)->toBe('processing')
            ->and($event->fresh()->attempts)->toBe(1)
            ->and($event->fresh()->processing_token)->toBe($event->processing_token);
    }

    public function test_failed_event_not_yet_due_is_not_reclaimed_by_duplicate_delivery(): void
    {
        $payload = ['event' => 'charge.success', 'data' => ['reference' => 'NOT-DUE-001']];
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $event = PaymentWebhookEvent::create([
            'provider' => 'paystack',
            'provider_event_id' => hash('sha256', 'paystack:' . $body),
            'event' => 'charge.success',
            'reference' => 'NOT-DUE-001',
            'status' => 'failed',
            'payload' => $payload,
            'error_message' => 'Temporary failure.',
            'attempts' => 1,
            'next_attempt_at' => now('UTC')->addMinutes(5),
        ]);

        $confirmationService = Mockery::mock(PaymentConfirmationService::class);
        $confirmationService->shouldReceive('confirm')->never();
        $this->app->instance(PaymentConfirmationService::class, $confirmationService);

        $this->postSignedWebhook($payload)
            ->assertStatus(422)
            ->assertJson(['success' => false, 'message' => 'Unable to confirm payment.']);

        expect($event->fresh()->status)->toBe('failed')
            ->and($event->fresh()->attempts)->toBe(1)
            ->and($event->fresh()->error_message)->toBe('Temporary failure.');
    }

    public function test_due_failed_event_is_reclaimed_and_processed(): void
    {
        $payload = ['event' => 'charge.success', 'data' => ['reference' => 'DUE-RETRY-001']];
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $event = PaymentWebhookEvent::create([
            'provider' => 'paystack',
            'provider_event_id' => hash('sha256', 'paystack:' . $body),
            'event' => 'charge.success',
            'reference' => 'DUE-RETRY-001',
            'status' => 'failed',
            'payload' => $payload,
            'error_message' => 'Previous failure.',
            'attempts' => 1,
            'next_attempt_at' => now('UTC')->subMinute(),
        ]);

        $confirmationService = Mockery::mock(PaymentConfirmationService::class);
        $confirmationService->shouldReceive('confirm')
            ->once()
            ->with('DUE-RETRY-001')
            ->andReturn(Payment::factory()->make());
        $this->app->instance(PaymentConfirmationService::class, $confirmationService);

        $this->postSignedWebhook($payload)
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        expect($event->fresh()->status)->toBe('processed')
            ->and($event->fresh()->attempts)->toBe(2)
            ->and($event->fresh()->next_attempt_at)->toBeNull();
    }

    public function test_manual_review_event_is_acknowledged_without_reprocessing(): void
    {
        $payload = ['event' => 'charge.success', 'data' => ['reference' => 'MANUAL-REVIEW-001']];
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $event = PaymentWebhookEvent::create([
            'provider' => 'paystack',
            'provider_event_id' => hash('sha256', 'paystack:' . $body),
            'event' => 'charge.success',
            'reference' => 'MANUAL-REVIEW-001',
            'status' => 'manual_review',
            'payload' => $payload,
            'attempts' => 5,
        ]);

        $confirmationService = Mockery::mock(PaymentConfirmationService::class);
        $confirmationService->shouldReceive('confirm')->never();
        $this->app->instance(PaymentConfirmationService::class, $confirmationService);

        $this->postSignedWebhook($payload)
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        expect($event->fresh()->status)->toBe('manual_review')
            ->and($event->fresh()->attempts)->toBe(5);
    }

    /*
    |--------------------------------------------------------------------------
    | Initial payment handling
    |--------------------------------------------------------------------------
    */

    public function test_valid_initial_charge_is_confirmed(): void
    {
        $confirmationService = Mockery::mock(
            PaymentConfirmationService::class
        );

        $confirmationService
            ->shouldReceive('confirm')
            ->once()
            ->with(
                'MERCHANTOS-INITIAL-001'
            )
            ->andReturn(Payment::factory()->make());

        $this->app->instance(
            PaymentConfirmationService::class,
            $confirmationService
        );

        Payment::factory()->create([
            'reference' =>
            'MERCHANTOS-INITIAL-001',
        ]);

        $payload = [
            'event' => 'charge.success',
            'data' => [
                'reference' =>
                'MERCHANTOS-INITIAL-001',
            ],
        ];

        $response = $this->postSignedWebhook(
            $payload
        );

        $response
            ->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);
    }

    public function test_non_success_event_is_acknowledged_without_confirmation(): void
    {
        $confirmationService = Mockery::mock(
            PaymentConfirmationService::class
        );

        $confirmationService
            ->shouldReceive('confirm')
            ->never();

        $renewalService = Mockery::mock(
            SubscriptionRenewalService::class
        );

        $renewalService
            ->shouldReceive('renew')
            ->never();

        $this->app->instance(
            PaymentConfirmationService::class,
            $confirmationService
        );

        $this->app->instance(
            SubscriptionRenewalService::class,
            $renewalService
        );

        $payload = [
            'event' => 'charge.failed',
            'data' => [
                'reference' =>
                'MERCHANTOS-FAILED-001',
            ],
        ];

        $response = $this->postSignedWebhook(
            $payload
        );

        $response
            ->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);
    }

    public function test_charge_success_requires_payment_reference(): void
    {
        $payload = [
            'event' => 'charge.success',
            'data' => [],
        ];

        $response = $this->postSignedWebhook(
            $payload
        );

        $response
            ->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' =>
                'Payment reference missing.',
            ]);
    }

    public function test_webhook_does_not_require_authenticated_user(): void
    {
        $payload = [
            'event' => 'charge.success',
            'data' => [
                'reference' =>
                'MERCHANTOS-TEST-005',
            ],
        ];

        $confirmationService = Mockery::mock(
            PaymentConfirmationService::class
        );

        $confirmationService
            ->shouldReceive('confirm')
            ->once()
            ->with(
                'MERCHANTOS-TEST-005'
            )
            ->andReturn(Payment::factory()->make());

        $this->app->instance(
            PaymentConfirmationService::class,
            $confirmationService
        );

        Payment::factory()->create([
            'reference' =>
            'MERCHANTOS-TEST-005',
        ]);

        $this->assertGuest();

        $response = $this->postSignedWebhook(
            $payload
        );

        $response->assertStatus(200);
    }

    /*
    |--------------------------------------------------------------------------
    | Recurring payments
    |--------------------------------------------------------------------------
    */

    public function test_recurring_charge_renews_subscription(): void
    {
        $renewalService = Mockery::mock(
            SubscriptionRenewalService::class
        );

        $renewalService
            ->shouldReceive('renew')
            ->once()
            ->withArgs(function (array $data) {
                return
                    $data['reference'] ===
                    'MERCHANTOS-RECURRING-001'
                    &&
                    $data['customer_code'] ===
                    'CUS_001'
                    &&
                    $data['subscription_code'] ===
                    'SUB_001';
            })
            ->andReturn(Payment::factory()->make());

        $this->app->instance(
            SubscriptionRenewalService::class,
            $renewalService
        );

        $payload = [
            'event' => 'charge.success',

            'data' => [
                'reference' =>
                'MERCHANTOS-RECURRING-001',

                'amount' => 2000000,

                'currency' => 'NGN',

                'customer' => [
                    'customer_code' =>
                    'CUS_001',
                ],

                'subscription' => [
                    'subscription_code' =>
                    'SUB_001',
                ],
            ],
        ];

        $this->postSignedWebhook(
            $payload
        )->assertStatus(200);
    }

    public function test_invoice_payment_failed_dispatches_subscription_failure(): void
    {
        $business = Business::factory()->create();
        $plan = SubscriptionPlan::factory()->create();
        $subscription = Subscription::factory()->create([
            'business_id' => $business->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'provider' => 'paystack',
            'provider_customer_code' => 'CUS-INVOICE-FAIL',
            'provider_subscription_code' => 'SUB-INVOICE-FAIL',
        ]);

                $response = $this->postSignedWebhook([
            'event' => 'invoice.payment_failed',
            'data' => [
                'customer' => ['customer_code' => 'CUS-INVOICE-FAIL'],
                'subscription' => ['subscription_code' => 'SUB-INVOICE-FAIL'],
            ],
        ]);


        $response->assertStatus(200)->assertJson(['success' => true]);

        expect($subscription->fresh()->status)->toBe('past_due');
    }

    public function test_generic_charge_failed_does_not_change_subscription_state(): void
    {
        $business = Business::factory()->create();
        $plan = SubscriptionPlan::factory()->create();
        $subscription = Subscription::factory()->create([
            'business_id' => $business->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'provider' => 'paystack',
            'provider_customer_code' => 'CUS-GENERIC-FAIL',
            'provider_subscription_code' => 'SUB-GENERIC-FAIL',
        ]);

        $this->postSignedWebhook([
            'event' => 'charge.failed',
            'data' => [
                'customer' => ['customer_code' => 'CUS-GENERIC-FAIL'],
                'subscription' => ['subscription_code' => 'SUB-GENERIC-FAIL'],
            ],
        ])->assertStatus(200)->assertJson(['success' => true]);

        expect($subscription->fresh()->status)->toBe('active');
    }

    public function test_subscription_not_renew_dispatches_to_billing_service(): void
    {
        $billing = Mockery::mock(\App\Domains\Subscription\Services\SubscriptionBillingService::class);
        $billing->shouldReceive('markNonRenewing')->once()->with('paystack', 'SUB-NOT-RENEW');
        $this->app->instance(\App\Domains\Subscription\Services\SubscriptionBillingService::class, $billing);

        $this->postSignedWebhook([
            'event' => 'subscription.not_renew',
            'data' => ['subscription_code' => 'SUB-NOT-RENEW'],
        ])->assertStatus(200)->assertJson(['success' => true]);

        expect(PaymentWebhookEvent::query()->firstOrFail()->status)->toBe('processed');
    }

    public function test_subscription_disable_dispatches_to_billing_service(): void
    {
        $billing = Mockery::mock(\App\Domains\Subscription\Services\SubscriptionBillingService::class);
        $billing->shouldReceive('markDisabled')->once()->with('paystack', 'SUB-DISABLE');
        $this->app->instance(\App\Domains\Subscription\Services\SubscriptionBillingService::class, $billing);

        $this->postSignedWebhook([
            'event' => 'subscription.disable',
            'data' => ['subscription' => ['subscription_code' => 'SUB-DISABLE']],
        ])->assertStatus(200)->assertJson(['success' => true]);

        expect(PaymentWebhookEvent::query()->firstOrFail()->status)->toBe('processed');
    }

    public function test_subscription_lifecycle_events_without_codes_are_no_ops(): void
    {
        $billing = Mockery::mock(\App\Domains\Subscription\Services\SubscriptionBillingService::class);
        $billing->shouldReceive('markNonRenewing')->never();
        $billing->shouldReceive('markDisabled')->never();
        $this->app->instance(\App\Domains\Subscription\Services\SubscriptionBillingService::class, $billing);

        foreach (['subscription.not_renew', 'subscription.disable'] as $eventName) {
            $this->postSignedWebhook(['event' => $eventName, 'data' => []])
                ->assertStatus(200)
                ->assertJson(['success' => true]);
        }

        expect(PaymentWebhookEvent::query()->where('status', 'processed')->count())->toBe(2);
    }

    public function test_invoice_failure_without_required_identifiers_is_a_no_op(): void
    {
        $failure = Mockery::mock(SubscriptionPaymentFailureService::class);
        $failure->shouldReceive('fail')->never();
        $this->app->instance(SubscriptionPaymentFailureService::class, $failure);

        $this->postSignedWebhook([
            'event' => 'invoice.payment_failed',
            'data' => ['customer' => ['customer_code' => 'CUS-WITHOUT-SUB']],
        ])->assertStatus(200)->assertJson(['success' => true]);

        expect(PaymentWebhookEvent::query()->firstOrFail()->status)->toBe('processed');
    }

    public function test_failure_with_older_unverified_timestamp_does_not_reverse_successful_renewal(): void
    {
        $this->providerStatus = 'active';
        $this->providerInvoiceStatus = 'success';
        $business = Business::factory()->create();
        $plan = SubscriptionPlan::factory()->create();
        $subscription = Subscription::factory()->create([
            'business_id' => $business->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'provider' => 'paystack',
            'provider_customer_code' => 'CUS-ORDERING',
            'provider_subscription_code' => 'SUB-ORDERING',
        ]);
        Payment::factory()->create([
            'business_id' => $business->id,
            'subscription_id' => $subscription->id,
            'sale_id' => null,
            'amount' => $plan->price,
            'method' => 'paystack',
            'status' => 'paid',
            'reference' => 'REF-ORDERING-SUCCESS',
            'metadata' => [
                'type' => 'subscription_renewal',
                'provider' => 'paystack',
                'customer_code' => 'CUS-ORDERING',
                'subscription_code' => 'SUB-ORDERING',
                'plan_id' => $plan->id,
                'currency' => $plan->currency,
                'paid_at_source' => 'provider',
            ],
            'paid_at' => now(),
        ]);

        $this->postSignedWebhook([
            'event' => 'invoice.payment_failed',
            'data' => [
                'created_at' => '2020-01-01T00:00:00.000Z',
                'customer' => ['customer_code' => 'CUS-ORDERING'],
                'subscription' => ['subscription_code' => 'SUB-ORDERING'],
            ],
        ])->assertStatus(200)->assertJson(['success' => true]);

        expect($subscription->fresh()->status)->toBe('active');
    }

    /*
    |--------------------------------------------------------------------------
    | Webhook ledger
    |--------------------------------------------------------------------------
    */

    public function test_webhook_event_is_recorded(): void
    {
        $confirmationService = Mockery::mock(
            PaymentConfirmationService::class
        );

        $confirmationService
            ->shouldReceive('confirm')
            ->once()
            ->with(
                'MERCHANTOS-LEDGER-001'
            )
            ->andReturn(Payment::factory()->make());

        $this->app->instance(
            PaymentConfirmationService::class,
            $confirmationService
        );

        Payment::factory()->create([
            'reference' =>
            'MERCHANTOS-LEDGER-001',
        ]);

        $payload = [
            'event' => 'charge.success',
            'data' => [
                'reference' =>
                'MERCHANTOS-LEDGER-001',
            ],
        ];

        $this->postSignedWebhook(
            $payload
        )->assertStatus(200);

        $event = PaymentWebhookEvent::query()
            ->where('provider', 'paystack')
            ->first();

        expect($event)
            ->not->toBeNull();

        expect($event->provider)
            ->toBe('paystack');

        expect($event->event)
            ->toBe('charge.success');

        expect($event->reference)
            ->toBe(
                'MERCHANTOS-LEDGER-001'
            );

        expect($event->status)
            ->toBe('processed');

        expect($event->processed_at)
            ->not->toBeNull();

        expect($event->payload['event'])
            ->toBe('charge.success');

        expect(
            $event->payload['data']['reference']
        )->toBe(
            'MERCHANTOS-LEDGER-001'
        );
    }

    public function test_duplicate_webhook_event_is_not_processed_twice(): void
    {
        $confirmationService = Mockery::mock(
            PaymentConfirmationService::class
        );

        $confirmationService
            ->shouldReceive('confirm')
            ->once()
            ->with(
                'MERCHANTOS-DUPLICATE-001'
            )
            ->andReturn(Payment::factory()->make());

        $this->app->instance(
            PaymentConfirmationService::class,
            $confirmationService
        );

        Payment::factory()->create([
            'reference' =>
            'MERCHANTOS-DUPLICATE-001',
        ]);

        $payload = [
            'event' => 'charge.success',

            'data' => [
                'reference' =>
                'MERCHANTOS-DUPLICATE-001',
            ],
        ];

        $this->postSignedWebhook(
            $payload
        )->assertStatus(200);

        $this->postSignedWebhook(
            $payload
        )
            ->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        expect(
            PaymentWebhookEvent::query()
                ->where(
                    'provider',
                    'paystack'
                )
                ->count()
        )->toBe(1);
    }

    public function test_duplicate_webhook_does_not_execute_confirmation_again(): void
    {
        $confirmationService = Mockery::mock(
            PaymentConfirmationService::class
        );

        $confirmationService
            ->shouldReceive('confirm')
            ->once()
            ->with(
                'MERCHANTOS-DUPLICATE-002'
            )
            ->andReturn(Payment::factory()->make());

        $this->app->instance(
            PaymentConfirmationService::class,
            $confirmationService
        );

        Payment::factory()->create([
            'reference' =>
            'MERCHANTOS-DUPLICATE-002',
        ]);

        $payload = [
            'event' => 'charge.success',

            'data' => [
                'reference' =>
                'MERCHANTOS-DUPLICATE-002',
            ],
        ];

        $this->postSignedWebhook(
            $payload
        )->assertStatus(200);

        $this->postSignedWebhook(
            $payload
        )->assertStatus(200);

        $this->postSignedWebhook(
            $payload
        )->assertStatus(200);
    }

    public function test_failed_webhook_is_recorded(): void
    {
        $confirmationService = Mockery::mock(
            PaymentConfirmationService::class
        );

        $confirmationService
            ->shouldReceive('confirm')
            ->once()
            ->with(
                'MERCHANTOS-LEDGER-FAILED-001'
            )
            ->andThrow(
                new \RuntimeException(
                    'Payment confirmation failed.'
                )
            );

        $this->app->instance(
            PaymentConfirmationService::class,
            $confirmationService
        );

        Payment::factory()->create([
            'reference' =>
            'MERCHANTOS-LEDGER-FAILED-001',
        ]);

        $payload = [
            'event' => 'charge.success',

            'data' => [
                'reference' =>
                'MERCHANTOS-LEDGER-FAILED-001',
            ],
        ];

        $this->postSignedWebhook(
            $payload
        )
            ->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' =>
                'Unable to confirm payment.',
            ]);

        $event = PaymentWebhookEvent::query()
            ->where(
                'provider',
                'paystack'
            )
            ->first();

        expect($event)
            ->not->toBeNull();

        expect($event->status)
            ->toBe('failed');

        expect($event->error_message)
            ->toBe(
                'Payment confirmation failed.'
            );

        expect($event->attempts)->toBe(1)
            ->and($event->next_attempt_at)->not->toBeNull();

        expect($event->processed_at)
            ->toBeNull();
    }

    public function test_unsupported_event_is_recorded_as_processed(): void
    {
        $payload = [
            'event' => 'customer.updated',

            'data' => [
                'customer_code' =>
                'CUS_MERCHANTOS_001',
            ],
        ];

        $this->postSignedWebhook(
            $payload
        )
            ->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        $event = PaymentWebhookEvent::query()
            ->where(
                'provider',
                'paystack'
            )
            ->first();

        expect($event)
            ->not->toBeNull();

        expect($event->event)
            ->toBe('customer.updated');

        expect($event->status)
            ->toBe('processed');

        expect($event->processed_at)
            ->not->toBeNull();
    }

    public function test_failed_webhook_can_be_retried(): void
    {
        $payload = [
            'event' => 'charge.success',

            'data' => [
                'reference' => 'MERCHANTOS-RETRY-001',
            ],
        ];

        /*
     |--------------------------------------------------------------------------
     | Create the MerchantOS payment that the confirmation service
     | is expected to confirm.
     |--------------------------------------------------------------------------
     */

        $payment = Payment::factory()->create([
            'reference' => 'MERCHANTOS-RETRY-001',
            'status' => 'pending',
        ]);

        /*
     |--------------------------------------------------------------------------
     | One mock for both attempts.
     |--------------------------------------------------------------------------
     |
     | Attempt #1:
     |     throw an exception.
     |
     | Attempt #2:
     |     return the actual Payment model.
     |
     | PaymentConfirmationService::confirm() returns Payment,
     | therefore the successful mock MUST return a Payment object.
     |--------------------------------------------------------------------------
     */

        $attempt = 0;

        $confirmationService = Mockery::mock(
            PaymentConfirmationService::class
        );

        $confirmationService
            ->shouldReceive('confirm')
            ->twice()
            ->with('MERCHANTOS-RETRY-001')
            ->andReturnUsing(
                function () use (
                    &$attempt,
                    $payment
                ) {
                    $attempt++;

                    /*
                 * First webhook attempt fails.
                 */
                    if ($attempt === 1) {
                        throw new \RuntimeException(
                            'Temporary confirmation failure.'
                        );
                    }

                    /*
                 * Second webhook attempt succeeds.
                 *
                 * IMPORTANT:
                 * confirm() has a Payment return type.
                 */
                    return $payment;
                }
            );

        $this->app->instance(
            PaymentConfirmationService::class,
            $confirmationService
        );

        /*
     |--------------------------------------------------------------------------
     | FIRST ATTEMPT
     |--------------------------------------------------------------------------
     */

        $this->postSignedWebhook(
            $payload
        )
            ->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Unable to confirm payment.',
            ]);

        /*
     |--------------------------------------------------------------------------
     | Verify the webhook was recorded as failed.
     |--------------------------------------------------------------------------
     */

        $event = PaymentWebhookEvent::query()
            ->where('provider', 'paystack')
            ->where(
                'reference',
                'MERCHANTOS-RETRY-001'
            )
            ->first();

        expect($event)
            ->not->toBeNull();

        expect($event->status)
            ->toBe('failed');

        expect($event->error_message)
            ->toBe(
                'Temporary confirmation failure.'
            );

        expect($event->processed_at)
            ->toBeNull();

        // Make the scheduled retry due without changing the configured backoff.
        $event->forceFill([
            'next_attempt_at' => now('UTC')->subMinute(),
        ])->save();

        /*
     |--------------------------------------------------------------------------
     | SECOND ATTEMPT
     |--------------------------------------------------------------------------
     |
     | Same Paystack webhook.
     |
     | The controller should detect:
     *
     *     failed
     *        ↓
     *     retry
     *        ↓
     *     confirm()
     *        ↓
     *     Payment returned
     *        ↓
     *     processed
     |--------------------------------------------------------------------------
     */

        $this->postSignedWebhook(
            $payload
        )
            ->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        /*
     |--------------------------------------------------------------------------
     | Verify final webhook state.
     |--------------------------------------------------------------------------
     */

        $event->refresh();

        expect($event->status)
            ->toBe('processed');

        expect($event->error_message)
            ->toBeNull();

        expect($event->processed_at)
            ->not->toBeNull();

        /*
     |--------------------------------------------------------------------------
     | Verify only ONE ledger record exists.
     |--------------------------------------------------------------------------
     */

        expect(
            PaymentWebhookEvent::query()
                ->where('provider', 'paystack')
                ->where(
                    'provider_event_id',
                    $event->provider_event_id
                )
                ->count()
        )->toBe(1);

        /*
     |--------------------------------------------------------------------------
     | Verify confirmation was called twice.
     |--------------------------------------------------------------------------
     */

        expect($attempt)
            ->toBe(2);
    }

    public function test_existing_unclaimed_received_event_is_processed(): void
    {
        /*
     * This test verifies the database-level uniqueness invariant.
     *
     * We simulate the situation where another request has already
     * inserted the same webhook event before this request reaches
     * the controller's insert operation.
     */

        $payload = [
            'event' => 'charge.success',
            'data' => [
                'reference' => 'MERCHANTOS-RACE-001',
            ],
        ];

        $body = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES
        );

        $providerEventId = hash(
            'sha256',
            'paystack:' . $body
        );

        /*
     * An earlier delivery inserted the event but did not claim it.
     */
        PaymentWebhookEvent::create([
            'provider' => 'paystack',
            'provider_event_id' => $providerEventId,
            'event' => 'charge.success',
            'reference' => 'MERCHANTOS-RACE-001',
            'status' => 'received',
            'payload' => $payload,
        ]);

        /*
     * The unclaimed received event should be claimed and processed.
     */
        $confirmationService = Mockery::mock(
            PaymentConfirmationService::class
        );

        $confirmationService
            ->shouldReceive('confirm')
            ->once()
            ->with('MERCHANTOS-RACE-001')
            ->andReturn(Payment::factory()->make());

        $this->app->instance(
            PaymentConfirmationService::class,
            $confirmationService
        );

        /*
     * Request B receives the same event.
     */
        $response = $this->postSignedWebhook(
            $payload
        );

        $response
            ->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        $event = PaymentWebhookEvent::query()
            ->where('provider', 'paystack')
            ->where('provider_event_id', $providerEventId)
            ->sole();

        expect($event->status)->toBe('processed')
            ->and($event->attempts)->toBe(1)
            ->and($event->processing_token)->toBeNull();
    }

    public function test_webhook_duplicate_insert_is_not_allowed_by_database(): void
    {
        $payload = [
            'event' => 'charge.success',
            'data' => [
                'reference' => 'MERCHANTOS-RACE-002',
            ],
        ];

        $body = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES
        );

        $providerEventId = hash(
            'sha256',
            'paystack:' . $body
        );

        PaymentWebhookEvent::create([
            'provider' => 'paystack',
            'provider_event_id' => $providerEventId,
            'event' => 'charge.success',
            'reference' => 'MERCHANTOS-RACE-002',
            'status' => 'received',
            'payload' => $payload,
        ]);

        expect(
            fn() => PaymentWebhookEvent::create([
                'provider' => 'paystack',
                'provider_event_id' => $providerEventId,
                'event' => 'charge.success',
                'reference' => 'MERCHANTOS-RACE-002',
                'status' => 'received',
                'payload' => $payload,
            ])
        )->toThrow(
            \Illuminate\Database\UniqueConstraintViolationException::class
        );
    }

    public function test_recent_received_webhook_without_token_is_claimed_and_processed(): void
    {
        $payload = [
            'event' => 'charge.success',
            'data' => [
                'reference' => 'MERCHANTOS-RECENT-001',
            ],
        ];

        $body = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES
        );

        $providerEventId = hash(
            'sha256',
            'paystack:' . $body
        );

        $event = PaymentWebhookEvent::create([
            'provider' => 'paystack',
            'provider_event_id' => $providerEventId,
            'event' => 'charge.success',
            'reference' => 'MERCHANTOS-RECENT-001',
            'status' => 'received',
            'payload' => $payload,
            'processing_token' => null,
            'attempts' => 0,
        ]);

        $confirmationService = Mockery::mock(
            PaymentConfirmationService::class
        );

        $confirmationService
            ->shouldReceive('confirm')
            ->once()
            ->with('MERCHANTOS-RECENT-001')
            ->andReturn(Payment::factory()->make());

        $this->app->instance(
            PaymentConfirmationService::class,
            $confirmationService
        );

        $this->postSignedWebhook($payload)
            ->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        expect($event->fresh()->status)->toBe('processed')
            ->and($event->fresh()->attempts)->toBe(1)
            ->and($event->fresh()->processing_token)->toBeNull()
            ->and($event->fresh()->processing_started_at)->toBeNull();
    }

    public function test_stale_received_webhook_can_be_reclaimed(): void
    {
        $payload = [
            'event' => 'charge.success',
            'data' => [
                'reference' => 'MERCHANTOS-STALE-001',
            ],
        ];

        $body = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES
        );

        $providerEventId = hash(
            'sha256',
            'paystack:' . $body
        );

        $event = PaymentWebhookEvent::create([
            'provider' => 'paystack',
            'provider_event_id' => $providerEventId,
            'event' => 'charge.success',
            'reference' => 'MERCHANTOS-STALE-001',
            'status' => 'received',
            'payload' => $payload,
        ]);

        /*
     * Simulate a webhook processor that crashed more than
     * ten minutes ago.
     */
        $event->forceFill([
            'updated_at' => now()->subMinutes(11),
        ])->save();

        $confirmationService = Mockery::mock(
            PaymentConfirmationService::class
        );

        $confirmationService
            ->shouldReceive('confirm')
            ->once()
            ->with('MERCHANTOS-STALE-001')
            ->andReturn(Payment::factory()->make());

        $this->app->instance(
            PaymentConfirmationService::class,
            $confirmationService
        );

        $this->postSignedWebhook($payload)
            ->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        $event->refresh();

        expect($event->status)
            ->toBe('processed');

        expect($event->attempts)
            ->toBe(1);

        expect($event->processed_at)
            ->not->toBeNull();
    }

    public function test_malformed_signed_json_preserves_empty_event_behavior(): void
    {
        $rawBody = '{"event":';

        $this->postRawSignedWebhook($rawBody)
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        $event = PaymentWebhookEvent::query()->firstOrFail();
        expect($event->provider_event_id)->toBe(hash('sha256', 'paystack:' . $rawBody))
            ->and($event->event)->toBe('')
            ->and($event->payload)->toBe([])
            ->and($event->status)->toBe('processed');
    }

    public function test_signed_payload_without_event_field_preserves_empty_event_behavior(): void
    {
        $payload = ['data' => ['reference' => 'NO-EVENT-001']];

        $this->postSignedWebhook($payload)
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        $event = PaymentWebhookEvent::query()->firstOrFail();
        expect($event->event)->toBe('')
            ->and($event->reference)->toBe('NO-EVENT-001')
            ->and($event->status)->toBe('processed');
    }

    /*
    |--------------------------------------------------------------------------
    | Test helper
    |--------------------------------------------------------------------------
    */

    /**
     * Send a webhook using the exact raw JSON body that
     * was used to generate the HMAC signature.
     */
    private function postSignedWebhook(
        array $payload
    ) {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return $this->postRawSignedWebhook($body);
    }

    private function postRawSignedWebhook(string $body, ?string $signature = null)
    {
        $signature ??= hash_hmac('sha512', $body, $this->secret);

        return $this->call(
            'POST',
            '/api/webhooks/paystack',
            [],
            [],
            [],
            [
                'HTTP_X_PAYSTACK_SIGNATURE' =>
                $signature,

                'CONTENT_TYPE' =>
                'application/json',
            ],
            $body
        );
    }
}

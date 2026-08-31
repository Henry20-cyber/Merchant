<?php

namespace Tests\Feature\Payment;

use App\Domains\Organization\Models\Business;
use App\Domains\Payment\Models\Payment;
use App\Domains\Payment\Models\PaymentWebhookEvent;
use App\Domains\Payment\Services\PaymentConfirmationService;
use App\Domains\Payment\Services\SubscriptionPaymentFailureService;
use App\Domains\Payment\Services\SubscriptionRenewalService;
use App\Domains\Subscription\Models\Subscription;
use App\Domains\Subscription\Models\SubscriptionPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class PaystackWebhookTest extends TestCase
{
    use RefreshDatabase;

    private string $secret =
    'test-paystack-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.paystack.webhook_secret' =>
            $this->secret,
        ]);
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
            );

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
            );

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
            });

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
            );

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
            );

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
            );

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

    public function test_webhook_duplicate_event_race_is_handled_safely(): void
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
     * Pretend Request A already inserted the event.
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
     * The confirmation service must NOT execute because the
     * event is already being processed.
     */
        $confirmationService = Mockery::mock(
            PaymentConfirmationService::class
        );

        $confirmationService
            ->shouldReceive('confirm')
            ->never();

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

        expect(
            PaymentWebhookEvent::query()
                ->where('provider', 'paystack')
                ->where(
                    'provider_event_id',
                    $providerEventId
                )
                ->count()
        )->toBe(1);
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

    public function test_recent_received_webhook_is_not_processed_again(): void
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

    PaymentWebhookEvent::create([
        'provider' => 'paystack',
        'provider_event_id' => $providerEventId,
        'event' => 'charge.success',
        'reference' => 'MERCHANTOS-RECENT-001',
        'status' => 'received',
        'payload' => $payload,
    ]);

    $confirmationService = Mockery::mock(
        PaymentConfirmationService::class
    );

    $confirmationService
        ->shouldReceive('confirm')
        ->never();

    $this->app->instance(
        PaymentConfirmationService::class,
        $confirmationService
    );

    $this->postSignedWebhook($payload)
        ->assertStatus(200)
        ->assertJson([
            'success' => true,
        ]);
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
        ->with('MERCHANTOS-STALE-001');

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

    expect($event->processed_at)
        ->not->toBeNull();
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
        $body = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES
        );

        $signature = hash_hmac(
            'sha512',
            $body,
            $this->secret
        );

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

<?php

namespace Tests\Feature\Payment;

use App\Domains\Organization\Models\Business;
use App\Domains\Payment\Contracts\PaymentGateway;
use App\Domains\Payment\Models\Payment;
use App\Domains\Payment\Services\SubscriptionPaymentFailureService;
use App\Domains\Subscription\Models\Subscription;
use App\Domains\Subscription\Models\SubscriptionPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class SubscriptionPaymentFailureServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $providerStatus = 'attention';

    private string $providerInvoiceStatus = 'attention';

    private mixed $providerAmount = 500000;

    private mixed $providerCurrency = 'NGN';

    private array $providerDataOverrides = [];

    private bool $omitProviderInvoice = false;

    private ?\Throwable $providerException = null;

    protected function setUp(): void
    {
        parent::setUp();

        $gateway = Mockery::mock(PaymentGateway::class);
        $gateway->shouldReceive('fetchSubscription')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function (string $code): array {
                if ($this->providerException) {
                    throw $this->providerException;
                }

                $subscription = Subscription::query()
                    ->with('plan')
                    ->where('provider_subscription_code', $code)
                    ->firstOrFail();

                $providerData = array_merge([
                    'subscription_code' => $code,
                    'customer' => ['customer_code' => $subscription->provider_customer_code],
                    'plan' => ['currency' => $this->providerCurrency],
                    'amount' => $this->providerAmount,
                    'status' => $this->providerStatus,
                    'most_recent_invoice' => ['status' => $this->providerInvoiceStatus],
                ], $this->providerDataOverrides);

                if ($this->omitProviderInvoice) {
                    unset($providerData['most_recent_invoice']);
                }

                return [
                    'success' => true,
                    'data' => $providerData,
                ];
            });

        $this->app->instance(PaymentGateway::class, $gateway);
    }

    public function test_failed_payment_moves_active_subscription_to_past_due(): void
    {
        $business = Business::factory()->create();

        $plan = SubscriptionPlan::factory()->create([
            'price' => 5000,
            'currency' => 'NGN',
            'billing_interval' => 'monthly',
            'is_active' => true,
        ]);

        $subscription = Subscription::factory()->create([
            'business_id' => $business->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'provider' => 'paystack',
            'provider_customer_code' =>
                'CUS_FAILURE_001',
            'provider_subscription_code' =>
                'SUB_FAILURE_001',
        ]);

        $service = app(
            SubscriptionPaymentFailureService::class
        );

        $result = $service->fail([
            'reference' =>
                'MERCHANTOS-FAILURE-001',

            'customer_code' =>
                'CUS_FAILURE_001',

            'subscription_code' =>
                'SUB_FAILURE_001',

            'amount' => 500000,

            'currency' => 'NGN',
        ]);

        expect($result->status)
            ->toBe('past_due');

        $this->assertDatabaseHas(
            'subscriptions',
            [
                'id' =>
                    $subscription->id,

                'status' =>
                    'past_due',
            ]
        );
    }

    public function test_failed_payment_moves_grace_period_subscription_to_past_due(): void
    {
        $business = Business::factory()->create();

        $plan = SubscriptionPlan::factory()->create([
            'price' => 5000,
            'currency' => 'NGN',
            'billing_interval' => 'monthly',
            'is_active' => true,
        ]);

        $subscription = Subscription::factory()->create([
            'business_id' => $business->id,
            'plan_id' => $plan->id,
            'status' => 'grace_period',
            'provider' => 'paystack',
            'provider_customer_code' =>
                'CUS_FAILURE_002',
            'provider_subscription_code' =>
                'SUB_FAILURE_002',
            'grace_period_ends_at' =>
                now()->addDays(5),
        ]);

        $service = app(
            SubscriptionPaymentFailureService::class
        );

        $result = $service->fail([
            'reference' =>
                'MERCHANTOS-FAILURE-002',

            'customer_code' =>
                'CUS_FAILURE_002',

            'subscription_code' =>
                'SUB_FAILURE_002',

            'amount' => 500000,

            'currency' => 'NGN',
        ]);

        expect($result->status)
            ->toBe('past_due');

        expect($result->grace_period_ends_at)
            ->toBeNull();
    }

    public function test_cancelled_subscription_is_not_moved_to_past_due(): void
    {
        $business = Business::factory()->create();

        $plan = SubscriptionPlan::factory()->create([
            'price' => 5000,
            'currency' => 'NGN',
            'billing_interval' => 'monthly',
            'is_active' => true,
        ]);

        $subscription = Subscription::factory()->create([
            'business_id' => $business->id,
            'plan_id' => $plan->id,
            'status' => 'cancelled',
            'provider' => 'paystack',
            'provider_customer_code' =>
                'CUS_FAILURE_003',
            'provider_subscription_code' =>
                'SUB_FAILURE_003',
        ]);

        $service = app(
            SubscriptionPaymentFailureService::class
        );

        $result = $service->fail([
            'reference' =>
                'MERCHANTOS-FAILURE-003',

            'customer_code' =>
                'CUS_FAILURE_003',

            'subscription_code' =>
                'SUB_FAILURE_003',
        ]);

        expect($result->status)
            ->toBe('cancelled');
    }

    public function test_suspended_subscription_is_not_moved_to_past_due(): void
    {
        $business = Business::factory()->create();

        $plan = SubscriptionPlan::factory()->create([
            'price' => 5000,
            'currency' => 'NGN',
            'billing_interval' => 'monthly',
            'is_active' => true,
        ]);

        $subscription = Subscription::factory()->create([
            'business_id' => $business->id,
            'plan_id' => $plan->id,
            'status' => 'suspended',
            'provider' => 'paystack',
            'provider_customer_code' =>
                'CUS_FAILURE_004',
            'provider_subscription_code' =>
                'SUB_FAILURE_004',
        ]);

        $service = app(
            SubscriptionPaymentFailureService::class
        );

        $result = $service->fail([
            'reference' =>
                'MERCHANTOS-FAILURE-004',

            'customer_code' =>
                'CUS_FAILURE_004',

            'subscription_code' =>
                'SUB_FAILURE_004',
        ]);

        expect($result->status)
            ->toBe('suspended');
    }

    public function test_missing_customer_code_is_rejected(): void
    {
        $service = app(
            SubscriptionPaymentFailureService::class
        );

        expect(fn () => $service->fail([
            'reference' =>
                'MERCHANTOS-FAILURE-005',

            'subscription_code' =>
                'SUB_FAILURE_005',
        ]))->toThrow(
            ValidationException::class
        );
    }

    public function test_missing_subscription_code_is_rejected(): void
    {
        $service = app(
            SubscriptionPaymentFailureService::class
        );

        expect(fn () => $service->fail([
            'reference' =>
                'MERCHANTOS-FAILURE-006',

            'customer_code' =>
                'CUS_FAILURE_006',
        ]))->toThrow(
            ValidationException::class
        );
    }

    public function test_unknown_paystack_subscription_is_rejected(): void
    {
        $service = app(
            SubscriptionPaymentFailureService::class
        );

        expect(fn () => $service->fail([
            'reference' =>
                'MERCHANTOS-FAILURE-007',

            'customer_code' =>
                'CUS_UNKNOWN',

            'subscription_code' =>
                'SUB_UNKNOWN',
        ]))->toThrow(
            ValidationException::class
        );
    }

    public function test_repeated_failure_is_idempotent(): void
    {
        $business = Business::factory()->create();

        $plan = SubscriptionPlan::factory()->create([
            'price' => 5000,
            'currency' => 'NGN',
            'billing_interval' => 'monthly',
            'is_active' => true,
        ]);

        $subscription = Subscription::factory()->create([
            'business_id' => $business->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'provider' => 'paystack',
            'provider_customer_code' =>
                'CUS_FAILURE_008',
            'provider_subscription_code' =>
                'SUB_FAILURE_008',
        ]);

        $service = app(
            SubscriptionPaymentFailureService::class
        );

        $data = [
            'reference' =>
                'MERCHANTOS-FAILURE-008',

            'customer_code' =>
                'CUS_FAILURE_008',

            'subscription_code' =>
                'SUB_FAILURE_008',
        ];

        $first = $service->fail($data);

        $firstStatus = $first->status;

        $firstGraceEnd =
            $first->grace_period_ends_at;

        $second = $service->fail($data);

        expect($firstStatus)
            ->toBe('past_due');

        expect($second->status)
            ->toBe('past_due');

        expect($second->grace_period_ends_at)
            ->toBe($firstGraceEnd);
    }

    public function test_mismatched_transaction_reference_is_rejected_without_mutation(): void
    {
        $plan = SubscriptionPlan::factory()->create(['price' => 5000, 'currency' => 'NGN']);
        $target = $this->createFailureTestSubscription($plan, 'IDENTITY_TARGET');
        $other = $this->createFailureTestSubscription($plan, 'IDENTITY_OTHER');
        Payment::factory()->create([
            'business_id' => $other->business_id,
            'subscription_id' => $other->id,
            'sale_id' => null,
            'method' => 'paystack',
            'status' => 'paid',
            'reference' => 'REF-OTHER-SUBSCRIPTION',
        ]);

        expect(fn () => app(SubscriptionPaymentFailureService::class)->fail([
            'customer_code' => $target->provider_customer_code,
            'subscription_code' => $target->provider_subscription_code,
            'reference' => 'REF-OTHER-SUBSCRIPTION',
        ]))->toThrow(ValidationException::class);

        expect($target->fresh()->status)->toBe('active');
    }

    public function test_mismatched_customer_and_subscription_pair_is_rejected(): void
    {
        $plan = SubscriptionPlan::factory()->create(['price' => 5000, 'currency' => 'NGN']);
        $target = $this->createFailureTestSubscription($plan, 'PAIR_TARGET');
        $other = $this->createFailureTestSubscription($plan, 'PAIR_OTHER');

        expect(fn () => app(SubscriptionPaymentFailureService::class)->fail([
            'customer_code' => $target->provider_customer_code,
            'subscription_code' => $other->provider_subscription_code,
        ]))->toThrow(ValidationException::class);

        expect($target->fresh()->status)->toBe('active');
    }

    public function test_mismatched_optional_amount_and_currency_are_rejected(): void
    {
        $plan = SubscriptionPlan::factory()->create(['price' => 5000, 'currency' => 'NGN']);
        $subscription = $this->createFailureTestSubscription($plan, 'BAD_AMOUNT');
        $identity = [
            'customer_code' => $subscription->provider_customer_code,
            'subscription_code' => $subscription->provider_subscription_code,
        ];

        expect(fn () => app(SubscriptionPaymentFailureService::class)->fail($identity + ['amount' => 400000]))
            ->toThrow(ValidationException::class);
        expect(fn () => app(SubscriptionPaymentFailureService::class)->fail($identity + ['currency' => 'USD']))
            ->toThrow(ValidationException::class);
        expect($subscription->fresh()->status)->toBe('active');
    }

    public function test_sparse_failure_payload_remains_supported(): void
    {
        $plan = SubscriptionPlan::factory()->create(['price' => 5000, 'currency' => 'NGN']);
        $subscription = $this->createFailureTestSubscription($plan, 'SPARSE');

        $result = app(SubscriptionPaymentFailureService::class)->fail([
            'customer_code' => $subscription->provider_customer_code,
            'subscription_code' => $subscription->provider_subscription_code,
        ]);

        expect($result->status)->toBe('past_due');
    }

    public function test_historical_successful_renewal_does_not_suppress_current_provider_failure(): void
    {
        $subscription = $this->createSubscriptionWithSuccessfulRenewal('NO_FAILURE_TIME');

        $result = app(SubscriptionPaymentFailureService::class)->fail([
            'customer_code' => $subscription->provider_customer_code,
            'subscription_code' => $subscription->provider_subscription_code,
        ]);

        expect($result->status)->toBe('past_due');
    }

    public function test_malformed_failure_timestamp_does_not_override_current_provider_status(): void
    {
        $subscription = $this->createSubscriptionWithSuccessfulRenewal('BAD_FAILURE_TIME');

        $result = app(SubscriptionPaymentFailureService::class)->fail([
            'customer_code' => $subscription->provider_customer_code,
            'subscription_code' => $subscription->provider_subscription_code,
            'event_timestamp' => 'not-a-timestamp',
        ]);

        expect($result->status)->toBe('past_due');
    }

    public function test_provider_current_successful_invoice_prevents_stale_failure_from_reversing_renewal(): void
    {
        $this->providerStatus = 'active';
        $this->providerInvoiceStatus = 'success';
        $subscription = $this->createSubscriptionWithSuccessfulRenewal('STALE_FAILURE');

        $result = app(SubscriptionPaymentFailureService::class)->fail([
            'customer_code' => $subscription->provider_customer_code,
            'subscription_code' => $subscription->provider_subscription_code,
            'event_timestamp' => 'unverified-old-time',
        ]);

        expect($result->status)->toBe('active');
    }

    public function test_unknown_reference_is_accepted_only_with_current_provider_failure_confirmation(): void
    {
        $subscription = $this->createFailureTestSubscription(
            SubscriptionPlan::factory()->create(['price' => 5000, 'currency' => 'NGN']),
            'UNKNOWN_REFERENCE',
        );

        $result = app(SubscriptionPaymentFailureService::class)->fail([
            'customer_code' => $subscription->provider_customer_code,
            'subscription_code' => $subscription->provider_subscription_code,
            'reference' => 'REF-NOT-IN-LOCAL-PAYMENTS',
        ]);

        expect($result->status)->toBe('past_due');
    }

    public function test_unverified_provider_subscription_state_does_not_mutate_local_status(): void
    {
        $this->providerStatus = 'unknown-provider-state';
        $subscription = $this->createFailureTestSubscription(
            SubscriptionPlan::factory()->create(['price' => 5000, 'currency' => 'NGN']),
            'UNKNOWN_PROVIDER_STATE',
        );

        expect(fn () => app(SubscriptionPaymentFailureService::class)->fail([
            'customer_code' => $subscription->provider_customer_code,
            'subscription_code' => $subscription->provider_subscription_code,
        ]))->toThrow(\RuntimeException::class);

        expect($subscription->fresh()->status)->toBe('active');
    }

    public function test_unsupported_provider_currency_is_rejected(): void
    {
        $this->providerCurrency = 'USD';
        $subscription = $this->createFailureTestSubscription(
            SubscriptionPlan::factory()->create(['price' => 5000, 'currency' => 'NGN']),
            'UNSUPPORTED_CURRENCY',
        );

        expect(fn () => app(SubscriptionPaymentFailureService::class)->fail([
            'customer_code' => $subscription->provider_customer_code,
            'subscription_code' => $subscription->provider_subscription_code,
            'amount' => 500000,
            'currency' => 'USD',
        ]))->toThrow(ValidationException::class);

        expect($subscription->fresh()->status)->toBe('active');
    }

    public function test_changed_local_plan_price_does_not_reject_provider_confirmed_failure(): void
    {
        $this->providerDataOverrides['most_recent_invoice'] = [
            'status' => 'attention',
            'amount' => 500000,
        ];
        $subscription = $this->createFailureTestSubscription(
            SubscriptionPlan::factory()->create(['price' => 6000, 'currency' => 'NGN']),
            'CHANGED_LOCAL_PRICE',
        );

        $result = app(SubscriptionPaymentFailureService::class)->fail([
            'customer_code' => $subscription->provider_customer_code,
            'subscription_code' => $subscription->provider_subscription_code,
            'amount' => 500000,
        ]);

        expect($result->status)->toBe('past_due');
    }

    public function test_missing_provider_subscription_amount_does_not_mutate_local_status(): void
    {
        $this->providerAmount = null;
        $subscription = $this->createFailureTestSubscription(
            SubscriptionPlan::factory()->create(['price' => 5000, 'currency' => 'NGN']),
            'MISSING_PROVIDER_AMOUNT',
        );

        expect(fn () => app(SubscriptionPaymentFailureService::class)->fail([
            'customer_code' => $subscription->provider_customer_code,
            'subscription_code' => $subscription->provider_subscription_code,
        ]))->toThrow(ValidationException::class);

        expect($subscription->fresh()->status)->toBe('active');
    }

    public function test_webhook_amount_mismatch_with_provider_amount_does_not_mutate_local_status(): void
    {
        $subscription = $this->createFailureTestSubscription(
            SubscriptionPlan::factory()->create(['price' => 5000, 'currency' => 'NGN']),
            'WEBHOOK_AMOUNT_MISMATCH',
        );

        expect(fn () => app(SubscriptionPaymentFailureService::class)->fail([
            'customer_code' => $subscription->provider_customer_code,
            'subscription_code' => $subscription->provider_subscription_code,
            'amount' => 400000,
        ]))->toThrow(ValidationException::class);

        expect($subscription->fresh()->status)->toBe('active');
    }

    public function test_invoice_amount_mismatch_with_provider_amount_does_not_mutate_local_status(): void
    {
        $this->providerDataOverrides['most_recent_invoice'] = [
            'status' => 'attention',
            'amount' => 400000,
        ];
        $subscription = $this->createFailureTestSubscription(
            SubscriptionPlan::factory()->create(['price' => 5000, 'currency' => 'NGN']),
            'INVOICE_AMOUNT_MISMATCH',
        );

        expect(fn () => app(SubscriptionPaymentFailureService::class)->fail([
            'customer_code' => $subscription->provider_customer_code,
            'subscription_code' => $subscription->provider_subscription_code,
        ]))->toThrow(ValidationException::class);

        $this->providerDataOverrides['most_recent_invoice'] = ['status' => 'attention', 'amount' => -1];
        expect(fn () => app(SubscriptionPaymentFailureService::class)->fail([
            'customer_code' => $subscription->provider_customer_code,
            'subscription_code' => $subscription->provider_subscription_code,
        ]))->toThrow(ValidationException::class);

        expect($subscription->fresh()->status)->toBe('active');
    }

    public function test_attention_without_most_recent_invoice_is_processed(): void
    {
        $this->omitProviderInvoice = true;
        $subscription = $this->createFailureTestSubscription(
            SubscriptionPlan::factory()->create(['price' => 5000, 'currency' => 'NGN']),
            'NO_LATEST_INVOICE',
        );

        $result = app(SubscriptionPaymentFailureService::class)->fail([
            'customer_code' => $subscription->provider_customer_code,
            'subscription_code' => $subscription->provider_subscription_code,
        ]);

        expect($result->status)->toBe('past_due');
    }

    public function test_null_most_recent_invoice_is_rejected_without_mutation(): void
    {
        $this->providerDataOverrides['most_recent_invoice'] = null;
        $subscription = $this->createFailureTestSubscription(
            SubscriptionPlan::factory()->create(['price' => 5000, 'currency' => 'NGN']),
            'NULL_LATEST_INVOICE',
        );

        expect(fn () => app(SubscriptionPaymentFailureService::class)->fail([
            'customer_code' => $subscription->provider_customer_code,
            'subscription_code' => $subscription->provider_subscription_code,
        ]))->toThrow(\RuntimeException::class);

        expect($subscription->fresh()->status)->toBe('active');
    }

    public function test_present_invoice_with_null_amount_is_rejected_without_mutation(): void
    {
        $this->providerDataOverrides['most_recent_invoice'] = ['status' => 'attention', 'amount' => null];
        $subscription = $this->createFailureTestSubscription(
            SubscriptionPlan::factory()->create(['price' => 5000, 'currency' => 'NGN']),
            'INVOICE_NULL_AMOUNT',
        );

        expect(fn () => app(SubscriptionPaymentFailureService::class)->fail([
            'customer_code' => $subscription->provider_customer_code,
            'subscription_code' => $subscription->provider_subscription_code,
        ]))->toThrow(ValidationException::class);

        expect($subscription->fresh()->status)->toBe('active');
    }

    public function test_present_invoice_with_malformed_amount_is_rejected_without_mutation(): void
    {
        $this->providerDataOverrides['most_recent_invoice'] = ['status' => 'attention', 'amount' => 'bad'];
        $subscription = $this->createFailureTestSubscription(
            SubscriptionPlan::factory()->create(['price' => 5000, 'currency' => 'NGN']),
            'INVOICE_BAD_AMOUNT',
        );

        expect(fn () => app(SubscriptionPaymentFailureService::class)->fail([
            'customer_code' => $subscription->provider_customer_code,
            'subscription_code' => $subscription->provider_subscription_code,
        ]))->toThrow(ValidationException::class);

        expect($subscription->fresh()->status)->toBe('active');
    }

    public function test_present_invoice_without_amount_is_supported(): void
    {
        $this->providerDataOverrides['most_recent_invoice'] = ['status' => 'attention'];
        $subscription = $this->createFailureTestSubscription(
            SubscriptionPlan::factory()->create(['price' => 5000, 'currency' => 'NGN']),
            'INVOICE_NO_AMOUNT',
        );

        $result = app(SubscriptionPaymentFailureService::class)->fail([
            'customer_code' => $subscription->provider_customer_code,
            'subscription_code' => $subscription->provider_subscription_code,
        ]);

        expect($result->status)->toBe('past_due');
    }

    public function test_present_invoice_with_malformed_structure_or_status_is_rejected(): void
    {
        $this->providerDataOverrides['most_recent_invoice'] = ['amount' => 500000];
        $subscription = $this->createFailureTestSubscription(
            SubscriptionPlan::factory()->create(['price' => 5000, 'currency' => 'NGN']),
            'INVOICE_BAD_STRUCTURE',
        );

        expect(fn () => app(SubscriptionPaymentFailureService::class)->fail([
            'customer_code' => $subscription->provider_customer_code,
            'subscription_code' => $subscription->provider_subscription_code,
        ]))->toThrow(\RuntimeException::class);

        $this->providerDataOverrides['most_recent_invoice'] = ['status' => 123];
        expect(fn () => app(SubscriptionPaymentFailureService::class)->fail([
            'customer_code' => $subscription->provider_customer_code,
            'subscription_code' => $subscription->provider_subscription_code,
        ]))->toThrow(\RuntimeException::class);

        expect($subscription->fresh()->status)->toBe('active');
    }

    public function test_present_invoice_with_contradictory_currency_is_rejected_without_mutation(): void
    {
        $this->providerDataOverrides['most_recent_invoice'] = [
            'status' => 'attention',
            'currency' => 'USD',
        ];
        $subscription = $this->createFailureTestSubscription(
            SubscriptionPlan::factory()->create(['price' => 5000, 'currency' => 'NGN']),
            'INVOICE_BAD_CURRENCY',
        );

        expect(fn () => app(SubscriptionPaymentFailureService::class)->fail([
            'customer_code' => $subscription->provider_customer_code,
            'subscription_code' => $subscription->provider_subscription_code,
        ]))->toThrow(ValidationException::class);

        expect($subscription->fresh()->status)->toBe('active');
    }

    public function test_zero_provider_subscription_amount_is_rejected_without_mutation(): void
    {
        $this->providerAmount = 0;
        $subscription = $this->createFailureTestSubscription(
            SubscriptionPlan::factory()->create(['price' => 0, 'currency' => 'NGN']),
            'ZERO_PROVIDER_AMOUNT',
        );

        expect(fn () => app(SubscriptionPaymentFailureService::class)->fail([
            'customer_code' => $subscription->provider_customer_code,
            'subscription_code' => $subscription->provider_subscription_code,
        ]))->toThrow(ValidationException::class);

        expect($subscription->fresh()->status)->toBe('active');
    }

    public function test_attention_with_contradictory_invoice_status_does_not_mutate_local_status(): void
    {
        $this->providerDataOverrides['most_recent_invoice'] = ['status' => 'success'];
        $subscription = $this->createFailureTestSubscription(
            SubscriptionPlan::factory()->create(['price' => 5000, 'currency' => 'NGN']),
            'CONTRADICTORY_INVOICE',
        );

        expect(fn () => app(SubscriptionPaymentFailureService::class)->fail([
            'customer_code' => $subscription->provider_customer_code,
            'subscription_code' => $subscription->provider_subscription_code,
        ]))->toThrow(\RuntimeException::class);

        expect($subscription->fresh()->status)->toBe('active');
    }

    public function test_complete_provider_status_is_terminal(): void
    {
        $this->providerStatus = 'complete';
        $subscription = $this->createFailureTestSubscription(
            SubscriptionPlan::factory()->create(['price' => 5000, 'currency' => 'NGN']),
            'COMPLETE_STATUS',
        );

        $result = app(SubscriptionPaymentFailureService::class)->fail([
            'customer_code' => $subscription->provider_customer_code,
            'subscription_code' => $subscription->provider_subscription_code,
        ]);

        expect($result->status)->toBe('active');
    }

    public function test_completed_provider_status_is_terminal(): void
    {
        $this->providerStatus = 'completed';
        $subscription = $this->createFailureTestSubscription(
            SubscriptionPlan::factory()->create(['price' => 5000, 'currency' => 'NGN']),
            'COMPLETED_STATUS',
        );

        $result = app(SubscriptionPaymentFailureService::class)->fail([
            'customer_code' => $subscription->provider_customer_code,
            'subscription_code' => $subscription->provider_subscription_code,
        ]);

        expect($result->status)->toBe('active');
    }

    public function test_provider_customer_identity_mismatch_does_not_mutate_local_status(): void
    {
        $this->providerDataOverrides['customer'] = ['customer_code' => 'CUS_WRONG'];
        $subscription = $this->createFailureTestSubscription(
            SubscriptionPlan::factory()->create(['price' => 5000, 'currency' => 'NGN']),
            'PROVIDER_CUSTOMER_MISMATCH',
        );

        expect(fn () => app(SubscriptionPaymentFailureService::class)->fail([
            'customer_code' => $subscription->provider_customer_code,
            'subscription_code' => $subscription->provider_subscription_code,
        ]))->toThrow(\RuntimeException::class);

        expect($subscription->fresh()->status)->toBe('active');
    }

    public function test_provider_subscription_identity_mismatch_does_not_mutate_local_status(): void
    {
        $this->providerDataOverrides['subscription_code'] = 'SUB_WRONG';
        $subscription = $this->createFailureTestSubscription(
            SubscriptionPlan::factory()->create(['price' => 5000, 'currency' => 'NGN']),
            'PROVIDER_SUBSCRIPTION_MISMATCH',
        );

        expect(fn () => app(SubscriptionPaymentFailureService::class)->fail([
            'customer_code' => $subscription->provider_customer_code,
            'subscription_code' => $subscription->provider_subscription_code,
        ]))->toThrow(\RuntimeException::class);

        expect($subscription->fresh()->status)->toBe('active');
    }

    public function test_provider_timeout_does_not_mutate_local_status(): void
    {
        $this->providerException = new \RuntimeException('Paystack request timed out.');
        $subscription = $this->createFailureTestSubscription(
            SubscriptionPlan::factory()->create(['price' => 5000, 'currency' => 'NGN']),
            'PROVIDER_TIMEOUT',
        );

        expect(fn () => app(SubscriptionPaymentFailureService::class)->fail([
            'customer_code' => $subscription->provider_customer_code,
            'subscription_code' => $subscription->provider_subscription_code,
        ]))->toThrow(\RuntimeException::class);

        expect($subscription->fresh()->status)->toBe('active');
    }

    public function test_malformed_provider_response_does_not_mutate_local_status(): void
    {
        $this->providerDataOverrides['amount'] = 'not-an-amount';
        $subscription = $this->createFailureTestSubscription(
            SubscriptionPlan::factory()->create(['price' => 5000, 'currency' => 'NGN']),
            'MALFORMED_PROVIDER_AMOUNT',
        );

        expect(fn () => app(SubscriptionPaymentFailureService::class)->fail([
            'customer_code' => $subscription->provider_customer_code,
            'subscription_code' => $subscription->provider_subscription_code,
        ]))->toThrow(ValidationException::class);

        expect($subscription->fresh()->status)->toBe('active');
    }

    private function createFailureTestSubscription(SubscriptionPlan $plan, string $suffix): Subscription
    {
        return Subscription::factory()->create([
            'business_id' => Business::factory()->create()->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'provider' => 'paystack',
            'provider_customer_code' => 'CUS_'.$suffix,
            'provider_subscription_code' => 'SUB_'.$suffix,
        ]);
    }

    private function createSubscriptionWithSuccessfulRenewal(string $suffix): Subscription
    {
        $plan = SubscriptionPlan::factory()->create(['price' => 5000, 'currency' => 'NGN']);
        $subscription = $this->createFailureTestSubscription($plan, $suffix);
        Payment::factory()->create([
            'business_id' => $subscription->business_id,
            'subscription_id' => $subscription->id,
            'sale_id' => null,
            'amount' => $plan->price,
            'method' => 'paystack',
            'status' => 'paid',
            'reference' => 'REF-RENEWAL-'.$suffix,
            'metadata' => [
                'type' => 'subscription_renewal',
                'provider' => 'paystack',
                'customer_code' => $subscription->provider_customer_code,
                'subscription_code' => $subscription->provider_subscription_code,
                'plan_id' => $plan->id,
                'currency' => 'NGN',
                'paid_at_source' => 'provider',
            ],
            'paid_at' => now(),
        ]);

        return $subscription;
    }
}

<?php

namespace Tests\Feature\Payment;

use App\Domains\Organization\Models\Business;
use App\Domains\Payment\Services\SubscriptionPaymentFailureService;
use App\Domains\Subscription\Models\Subscription;
use App\Domains\Subscription\Models\SubscriptionPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SubscriptionPaymentFailureServiceTest extends TestCase
{
    use RefreshDatabase;

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
}
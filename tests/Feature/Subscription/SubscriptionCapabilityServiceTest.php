<?php

namespace Tests\Feature\Subscription;

use App\Domains\Organization\Models\Business;
use App\Domains\Subscription\Models\Subscription;
use App\Domains\Subscription\Models\SubscriptionPlan;
use App\Domains\Subscription\Services\SubscriptionCapabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SubscriptionCapabilityServiceTest extends TestCase
{
    use RefreshDatabase;

    private SubscriptionCapabilityService $capabilities;

    protected function setUp(): void
    {
        parent::setUp();

        $this->capabilities = app(
            SubscriptionCapabilityService::class
        );
    }

    private function createBusiness(): Business
    {
        return Business::factory()->create();
    }

    private function createPlan(
        array $features = []
    ): SubscriptionPlan {
        return SubscriptionPlan::factory()->create([
            'is_active' => true,
            'features' => $features,
        ]);
    }

    private function createSubscription(
        Business $business,
        SubscriptionPlan $plan,
        array $attributes = []
    ): Subscription {
        return Subscription::factory()->create(
            array_merge([
                'business_id' => $business->id,
                'plan_id' => $plan->id,
                'status' => 'active',
                'current_period_start' => now()->subDay(),
                'current_period_end' => now()->addMonth(),
            ], $attributes)
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Enabled capability
    |--------------------------------------------------------------------------
    */

    public function test_enabled_capability_is_allowed(): void
    {
        $business = $this->createBusiness();

        $plan = $this->createPlan([
            'receipts' => true,
        ]);

        $subscription = $this->createSubscription(
            $business,
            $plan
        );

        expect(
            $this->capabilities->allows(
                $subscription,
                'receipts'
            )
        )->toBeTrue();
    }

    /*
    |--------------------------------------------------------------------------
    | Disabled capability
    |--------------------------------------------------------------------------
    */

    public function test_disabled_capability_is_rejected(): void
    {
        $business = $this->createBusiness();

        $plan = $this->createPlan([
            'receipts' => false,
        ]);

        $subscription = $this->createSubscription(
            $business,
            $plan
        );

        expect(
            $this->capabilities->allows(
                $subscription,
                'receipts'
            )
        )->toBeFalse();
    }

    /*
    |--------------------------------------------------------------------------
    | Missing capability
    |--------------------------------------------------------------------------
    */

    public function test_missing_capability_is_rejected(): void
    {
        $business = $this->createBusiness();

        $plan = $this->createPlan([
            'pos' => true,
            'inventory' => true,
        ]);

        $subscription = $this->createSubscription(
            $business,
            $plan
        );

        expect(
            $this->capabilities->allows(
                $subscription,
                'receipts'
            )
        )->toBeFalse();
    }

    /*
    |--------------------------------------------------------------------------
    | Expired subscription
    |--------------------------------------------------------------------------
    */

    public function test_expired_subscription_cannot_use_capability(): void
    {
        $business = $this->createBusiness();

        $plan = $this->createPlan([
            'receipts' => true,
        ]);

        $subscription = $this->createSubscription(
            $business,
            $plan,
            [
                'status' => 'active',
                'current_period_end' => now()->subMinute(),
            ]
        );

        expect(
            $this->capabilities->allows(
                $subscription,
                'receipts'
            )
        )->toBeFalse();
    }

    /*
    |--------------------------------------------------------------------------
    | Cancelled subscription
    |--------------------------------------------------------------------------
    */

    public function test_cancelled_subscription_cannot_use_capability(): void
    {
        $business = $this->createBusiness();

        $plan = $this->createPlan([
            'receipts' => true,
        ]);

        $subscription = $this->createSubscription(
            $business,
            $plan,
            [
                'status' => 'cancelled',
                'cancelled_at' => now(),
            ]
        );

        expect(
            $this->capabilities->allows(
                $subscription,
                'receipts'
            )
        )->toBeFalse();
    }

    /*
    |--------------------------------------------------------------------------
    | Grace period
    |--------------------------------------------------------------------------
    */

    public function test_active_grace_period_can_use_capability(): void
    {
        $business = $this->createBusiness();

        $plan = $this->createPlan([
            'receipts' => true,
        ]);

        $subscription = $this->createSubscription(
            $business,
            $plan,
            [
                'status' => 'grace_period',
                'current_period_end' => now()->subDay(),
                'grace_period_ends_at' => now()->addDays(3),
            ]
        );

        expect(
            $this->capabilities->allows(
                $subscription,
                'receipts'
            )
        )->toBeTrue();
    }

    /*
    |--------------------------------------------------------------------------
    | Expired grace period
    |--------------------------------------------------------------------------
    */

    public function test_expired_grace_period_cannot_use_capability(): void
    {
        $business = $this->createBusiness();

        $plan = $this->createPlan([
            'receipts' => true,
        ]);

        $subscription = $this->createSubscription(
            $business,
            $plan,
            [
                'status' => 'grace_period',
                'current_period_end' => now()->subDays(5),
                'grace_period_ends_at' => now()->subMinute(),
            ]
        );

        expect(
            $this->capabilities->allows(
                $subscription,
                'receipts'
            )
        )->toBeFalse();
    }

    /*
    |--------------------------------------------------------------------------
    | Inactive plan
    |--------------------------------------------------------------------------
    */

    public function test_inactive_plan_cannot_grant_capability(): void
    {
        $business = $this->createBusiness();

        $plan = $this->createPlan([
            'receipts' => true,
        ]);

        $plan->forceFill([
            'is_active' => false,
        ])->save();

        $subscription = $this->createSubscription(
            $business,
            $plan
        );

        expect(
            $this->capabilities->allows(
                $subscription,
                'receipts'
            )
        )->toBeFalse();
    }

    /*
    |--------------------------------------------------------------------------
    | Require
    |--------------------------------------------------------------------------
    */

    public function test_require_allows_enabled_capability(): void
    {
        $business = $this->createBusiness();

        $plan = $this->createPlan([
            'receipts' => true,
        ]);

        $subscription = $this->createSubscription(
            $business,
            $plan
        );

        $this->capabilities->require(
            $subscription,
            'receipts'
        );

        expect(true)->toBeTrue();
    }

    public function test_require_throws_when_capability_is_missing(): void
    {
        $business = $this->createBusiness();

        $plan = $this->createPlan([
            'receipts' => false,
        ]);

        $subscription = $this->createSubscription(
            $business,
            $plan
        );

        expect(fn () => $this->capabilities->require(
            $subscription,
            'receipts'
        ))->toThrow(
            ValidationException::class
        );
    }
}

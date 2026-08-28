<?php

namespace Tests\Feature\Subscription;

use App\Domains\Organization\Models\Business;
use App\Domains\Subscription\Models\Subscription;
use App\Domains\Subscription\Models\SubscriptionPlan;
use App\Domains\Subscription\Services\SubscriptionLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionLifecycleServiceTest extends TestCase
{
    use RefreshDatabase;

    private SubscriptionLifecycleService $lifecycle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lifecycle = app(
            SubscriptionLifecycleService::class
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function createPlan(
        string $interval = 'monthly'
    ): SubscriptionPlan {
        return SubscriptionPlan::factory()->create([
            'billing_interval' => $interval,
            'is_active' => true,
        ]);
    }

    private function createBusiness(): Business
    {
        return Business::factory()->create();
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
            ], $attributes)
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Expired trial
    |--------------------------------------------------------------------------
    */

    public function test_expired_trial_becomes_past_due(): void
    {
        $business = $this->createBusiness();
        $plan = $this->createPlan();

        $subscription = $this->createSubscription(
            $business,
            $plan,
            [
                'status' => 'trial',
                'current_period_start' => now()->subDays(8),
                'current_period_end' => now()->subDay(),
            ]
        );

        $this->lifecycle->process($subscription);

        expect(
            $subscription->refresh()->status
        )->toBe('past_due');
    }

    /*
    |--------------------------------------------------------------------------
    | Expired active subscription
    |--------------------------------------------------------------------------
    */

    public function test_expired_active_subscription_becomes_past_due(): void
    {
        $business = $this->createBusiness();
        $plan = $this->createPlan();

        $subscription = $this->createSubscription(
            $business,
            $plan,
            [
                'status' => 'active',
                'current_period_start' => now()->subMonth(),
                'current_period_end' => now()->subDay(),
            ]
        );

        $this->lifecycle->process($subscription);

        expect(
            $subscription->refresh()->status
        )->toBe('past_due');
    }

    /*
    |--------------------------------------------------------------------------
    | Past due → grace period
    |--------------------------------------------------------------------------
    */

    public function test_past_due_subscription_enters_grace_period(): void
    {
        $business = $this->createBusiness();
        $plan = $this->createPlan();

        $subscription = $this->createSubscription(
            $business,
            $plan,
            [
                'status' => 'past_due',
                'current_period_end' => now()->subDay(),
                'grace_period_ends_at' => null,
            ]
        );

        $this->lifecycle->process($subscription);

        $subscription->refresh();

        expect($subscription->status)
            ->toBe('grace_period');

        expect($subscription->grace_period_ends_at)
            ->not->toBeNull();

        expect($subscription->grace_period_ends_at->isFuture())
            ->toBeTrue();
    }

    /*
    |--------------------------------------------------------------------------
    | Grace period remains usable
    |--------------------------------------------------------------------------
    */

    public function test_active_grace_period_is_not_restricted(): void
    {
        $business = $this->createBusiness();
        $plan = $this->createPlan();

        $subscription = $this->createSubscription(
            $business,
            $plan,
            [
                'status' => 'grace_period',
                'current_period_end' => now()->subDays(2),
                'grace_period_ends_at' => now()->addDays(5),
            ]
        );

        $this->lifecycle->process($subscription);

        expect(
            $subscription->refresh()->status
        )->toBe('grace_period');
    }

    /*
    |--------------------------------------------------------------------------
    | Expired grace period
    |--------------------------------------------------------------------------
    */

    public function test_expired_grace_period_becomes_restricted(): void
    {
        $business = $this->createBusiness();
        $plan = $this->createPlan();

        $subscription = $this->createSubscription(
            $business,
            $plan,
            [
                'status' => 'grace_period',
                'current_period_end' => now()->subDays(10),
                'grace_period_ends_at' => now()->subDay(),
            ]
        );

        $this->lifecycle->process($subscription);

        $subscription->refresh();

        expect($subscription->status)
            ->toBe('restricted');

        expect($subscription->restricted_at)
            ->not->toBeNull();
    }

    /*
    |--------------------------------------------------------------------------
    | Restricted subscription
    |--------------------------------------------------------------------------
    */

    public function test_restricted_subscription_is_not_immediately_suspended(): void
    {
        $business = $this->createBusiness();
        $plan = $this->createPlan();

        $subscription = $this->createSubscription(
            $business,
            $plan,
            [
                'status' => 'restricted',
                'restricted_at' => now()->subDays(5),
            ]
        );

        $this->lifecycle->process($subscription);

        expect(
            $subscription->refresh()->status
        )->toBe('restricted');
    }

    /*
    |--------------------------------------------------------------------------
    | Prolonged restriction
    |--------------------------------------------------------------------------
    */

    public function test_prolonged_restriction_becomes_suspended(): void
    {
        $business = $this->createBusiness();
        $plan = $this->createPlan();

        $subscription = $this->createSubscription(
            $business,
            $plan,
            [
                'status' => 'restricted',
                'restricted_at' => now()->subDays(31),
            ]
        );

        $this->lifecycle->process($subscription);

        expect(
            $subscription->refresh()->status
        )->toBe('suspended');
    }

    /*
    |--------------------------------------------------------------------------
    | Future subscriptions
    |--------------------------------------------------------------------------
    */

    public function test_future_active_subscription_is_ignored(): void
    {
        $business = $this->createBusiness();
        $plan = $this->createPlan();

        $subscription = $this->createSubscription(
            $business,
            $plan,
            [
                'status' => 'active',
                'current_period_start' => now()->addDay(),
                'current_period_end' => now()->addMonth(),
            ]
        );

        $this->lifecycle->process($subscription);

        expect(
            $subscription->refresh()->status
        )->toBe('active');
    }

    /*
    |--------------------------------------------------------------------------
    | Cancelled subscriptions
    |--------------------------------------------------------------------------
    */

    public function test_cancelled_subscription_is_ignored(): void
    {
        $business = $this->createBusiness();
        $plan = $this->createPlan();

        $subscription = $this->createSubscription(
            $business,
            $plan,
            [
                'status' => 'cancelled',
                'current_period_end' => now()->subDay(),
                'cancelled_at' => now()->subDay(),
            ]
        );

        $this->lifecycle->process($subscription);

        expect(
            $subscription->refresh()->status
        )->toBe('cancelled');
    }

    /*
    |--------------------------------------------------------------------------
    | Suspended subscriptions
    |--------------------------------------------------------------------------
    */

    public function test_suspended_subscription_is_ignored(): void
    {
        $business = $this->createBusiness();
        $plan = $this->createPlan();

        $subscription = $this->createSubscription(
            $business,
            $plan,
            [
                'status' => 'suspended',
                'ended_at' => now()->subDay(),
            ]
        );

        $this->lifecycle->process($subscription);

        expect(
            $subscription->refresh()->status
        )->toBe('suspended');
    }

    /*
    |--------------------------------------------------------------------------
    | Idempotency
    |--------------------------------------------------------------------------
    */

    public function test_processing_restricted_subscription_twice_does_not_change_restricted_at(): void
    {
        $business = $this->createBusiness();
        $plan = $this->createPlan();

        $subscription = $this->createSubscription(
            $business,
            $plan,
            [
                'status' => 'grace_period',
                'current_period_end' => now()->subDays(10),
                'grace_period_ends_at' => now()->subDay(),
            ]
        );

        $this->lifecycle->process($subscription);

        $subscription->refresh();

        $restrictedAt = $subscription->restricted_at;

        $this->lifecycle->process($subscription);

        $subscription->refresh();

        expect($subscription->status)
            ->toBe('restricted');

        expect($subscription->restricted_at->equalTo($restrictedAt))
            ->toBeTrue();
    }
}
<?php

namespace Tests\Feature\Subscription;

use App\Domains\Organization\Models\Business;
use App\Domains\Subscription\Models\Subscription;
use App\Domains\Subscription\Models\SubscriptionPlan;
use App\Domains\Subscription\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SubscriptionServiceTest extends TestCase
{
    use RefreshDatabase;

    private SubscriptionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(SubscriptionService::class);
    }

    private function createPlan(
        string $slug = 'medium',
        bool $active = true
    ): SubscriptionPlan {
        $config = match ($slug) {
            'low' => [
                'name' => 'Low',
                'price' => 5000,
                'customer_limit' => 50,
                'user_limit' => 3,
                'branch_limit' => 1,
            ],

            'medium' => [
                'name' => 'Medium',
                'price' => 10000,
                'customer_limit' => 100,
                'user_limit' => 10,
                'branch_limit' => 3,
            ],

            'large' => [
                'name' => 'Large',
                'price' => 20000,
                'customer_limit' => 500,
                'user_limit' => 50,
                'branch_limit' => 10,
            ],

            default => [
                'name' => ucfirst($slug),
                'price' => 10000,
                'customer_limit' => 100,
                'user_limit' => 10,
                'branch_limit' => 3,
            ],
        };

        return SubscriptionPlan::create([
            'name' => $config['name'],
            'slug' => $slug,
            'description' => 'Test subscription plan.',
            'price' => $config['price'],
            'currency' => 'NGN',
            'billing_interval' => 'monthly',

            'customer_limit' => $config['customer_limit'],
            'user_limit' => $config['user_limit'],
            'branch_limit' => $config['branch_limit'],

            'features' => [
                'pos' => true,
                'customer_management' => true,
                'inventory' => true,
                'basic_reports' => true,
                'advanced_reports' => true,
                'multiple_branches' => true,
                'advanced_analytics' => true,
            ],

            'is_active' => $active,
        ]);
    }

    private function createBusiness(): Business
    {
        return Business::factory()->create();
    }

    public function test_business_can_start_trial(): void
    {
        $business = $this->createBusiness();
        $plan = $this->createPlan();

        $subscription = $this->service->createTrial(
            $business,
            $plan,
            7
        );

        expect($subscription)
            ->toBeInstanceOf(Subscription::class);

        expect($subscription->business_id)
            ->toBe($business->id);

        expect($subscription->plan_id)
            ->toBe($plan->id);

        expect($subscription->status)
            ->toBe('trial');

        expect($subscription->starts_at)
            ->not->toBeNull();

        expect($subscription->current_period_end)
            ->not->toBeNull();
    }

    public function test_trial_cannot_use_inactive_plan(): void
    {
        $business = $this->createBusiness();

        $plan = $this->createPlan(
            'medium',
            false
        );

        expect(fn () =>
            $this->service->createTrial(
                $business,
                $plan
            )
        )->toThrow(ValidationException::class);
    }

    public function test_business_cannot_have_two_subscriptions(): void
    {
        $business = $this->createBusiness();
        $plan = $this->createPlan();

        $this->service->createTrial(
            $business,
            $plan
        );

        expect(fn () =>
            $this->service->createTrial(
                $business,
                $plan
            )
        )->toThrow(ValidationException::class);
    }

    public function test_subscription_can_be_activated(): void
    {
        $business = $this->createBusiness();
        $plan = $this->createPlan();

        $subscription = $this->service->createTrial(
            $business,
            $plan
        );

        $this->service->activate($subscription);

        expect($subscription->fresh()->status)
            ->toBe('active');
    }

    public function test_subscription_can_be_marked_past_due(): void
    {
        $business = $this->createBusiness();
        $plan = $this->createPlan();

        $subscription = $this->service->createTrial(
            $business,
            $plan
        );

        $this->service->markPastDue($subscription);

        expect($subscription->fresh()->status)
            ->toBe('past_due');
    }

    public function test_subscription_can_enter_grace_period(): void
    {
        $business = $this->createBusiness();
        $plan = $this->createPlan();

        $subscription = $this->service->createTrial(
            $business,
            $plan
        );

        $this->service->enterGracePeriod(
            $subscription,
            7
        );

        $subscription->refresh();

        expect($subscription->status)
            ->toBe('grace_period');

        expect($subscription->current_period_end)
            ->not->toBeNull();
    }

    public function test_subscription_can_be_restricted(): void
    {
        $business = $this->createBusiness();
        $plan = $this->createPlan();

        $subscription = $this->service->createTrial(
            $business,
            $plan
        );

        $this->service->restrict($subscription);

        expect($subscription->fresh()->status)
            ->toBe('restricted');
    }

    public function test_subscription_can_be_suspended(): void
    {
        $business = $this->createBusiness();
        $plan = $this->createPlan();

        $subscription = $this->service->createTrial(
            $business,
            $plan
        );

        $this->service->suspend($subscription);

        expect($subscription->fresh()->status)
            ->toBe('suspended');
    }

    public function test_active_subscription_is_usable(): void
    {
        $business = $this->createBusiness();
        $plan = $this->createPlan();

        $subscription = $this->service->createTrial(
            $business,
            $plan
        );

        $this->service->activate($subscription);

        expect(
            $this->service->isUsable(
                $subscription->fresh()
            )
        )->toBeTrue();
    }

    public function test_restricted_subscription_is_not_usable(): void
    {
        $business = $this->createBusiness();
        $plan = $this->createPlan();

        $subscription = $this->service->createTrial(
            $business,
            $plan
        );

        $this->service->restrict($subscription);

        expect(
            $this->service->isUsable(
                $subscription->fresh()
            )
        )->toBeFalse();
    }

    public function test_subscription_can_change_plan(): void
    {
        $business = $this->createBusiness();

        $low = $this->createPlan('low');
        $large = $this->createPlan('large');

        $subscription = $this->service->createTrial(
            $business,
            $low
        );

        $this->service->changePlan(
            $subscription,
            $large
        );

        expect(
            $subscription->fresh()->plan_id
        )->toBe($large->id);
    }
}
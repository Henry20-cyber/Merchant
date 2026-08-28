<?php

namespace Tests\Feature\Subscription;

use App\Domains\Organization\Models\Business;
use App\Domains\Subscription\Models\Subscription;
use App\Domains\Subscription\Models\SubscriptionPlan;
use App\Domains\Payment\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_subscription_belongs_to_business(): void
    {
        $business = Business::factory()->create();

        $plan = SubscriptionPlan::create([
            'name' => 'Low',
            'slug' => 'low',
            'description' => 'For small businesses',
            'price' => 5000,
            'currency' => 'NGN',
            'billing_interval' => 'monthly',
            'customer_limit' => 50,
            'user_limit' => 3,
            'branch_limit' => 1,
            'features' => [
                'pos' => true,
                'inventory' => true,
                'advanced_reports' => false,
            ],
            'is_active' => true,
        ]);

        $subscription = Subscription::create([
            'business_id' => $business->id,
            'plan_id' => $plan->id,
            'status' => 'trial',
        ]);

        expect($subscription->business->id)
            ->toBe($business->id);
    }

    public function test_subscription_belongs_to_plan(): void
    {
        $business = Business::factory()->create();

        $plan = SubscriptionPlan::create([
            'name' => 'Medium',
            'slug' => 'medium',
            'description' => 'For growing businesses',
            'price' => 10000,
            'currency' => 'NGN',
            'billing_interval' => 'monthly',
            'customer_limit' => 100,
            'user_limit' => 10,
            'branch_limit' => 3,
            'features' => [
                'pos' => true,
                'inventory' => true,
                'advanced_reports' => true,
            ],
            'is_active' => true,
        ]);

        $subscription = Subscription::create([
            'business_id' => $business->id,
            'plan_id' => $plan->id,
            'status' => 'active',
        ]);

        expect($subscription->plan->id)
            ->toBe($plan->id);
    }

    public function test_plan_has_many_subscriptions(): void
    {
        $plan = SubscriptionPlan::create([
            'name' => 'Large',
            'slug' => 'large',
            'description' => 'For established businesses',
            'price' => 20000,
            'currency' => 'NGN',
            'billing_interval' => 'monthly',
            'customer_limit' => 500,
            'user_limit' => 50,
            'branch_limit' => 10,
            'features' => [
                'pos' => true,
                'inventory' => true,
                'advanced_reports' => true,
                'multiple_branches' => true,
            ],
            'is_active' => true,
        ]);

        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();

        Subscription::create([
            'business_id' => $businessA->id,
            'plan_id' => $plan->id,
            'status' => 'active',
        ]);

        Subscription::create([
            'business_id' => $businessB->id,
            'plan_id' => $plan->id,
            'status' => 'active',
        ]);

        expect($plan->subscriptions()->count())
            ->toBe(2);
    }

    public function test_business_has_one_subscription(): void
    {
        $business = Business::factory()->create();

        $plan = SubscriptionPlan::create([
            'name' => 'Low',
            'slug' => 'low',
            'description' => 'For small businesses',
            'price' => 5000,
            'currency' => 'NGN',
            'billing_interval' => 'monthly',
            'customer_limit' => 50,
            'user_limit' => 3,
            'branch_limit' => 1,
            'features' => [
                'pos' => true,
                'inventory' => true,
            ],
            'is_active' => true,
        ]);

        $subscription = Subscription::create([
            'business_id' => $business->id,
            'plan_id' => $plan->id,
            'status' => 'active',
        ]);

        expect($business->subscription->id)
            ->toBe($subscription->id);
    }

    public function test_plan_features_are_cast_to_array(): void
    {
        $plan = SubscriptionPlan::create([
            'name' => 'Low',
            'slug' => 'low',
            'price' => 5000,
            'currency' => 'NGN',
            'billing_interval' => 'monthly',
            'features' => [
                'pos' => true,
                'inventory' => true,
                'advanced_reports' => false,
            ],
            'is_active' => true,
        ]);

        $plan->refresh();

        expect($plan->features)
            ->toBeArray()
            ->toHaveKey('pos')
            ->toHaveKey('inventory');
    }

    public function test_subscription_dates_are_cast_to_datetime(): void
    {
        $business = Business::factory()->create();

        $plan = SubscriptionPlan::create([
            'name' => 'Low',
            'slug' => 'low',
            'price' => 5000,
            'currency' => 'NGN',
            'billing_interval' => 'monthly',
            'is_active' => true,
        ]);

        $date = now();

        $subscription = Subscription::create([
            'business_id' => $business->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => $date,
            'current_period_start' => $date,
            'current_period_end' => $date->copy()->addMonth(),
        ]);

        $subscription->refresh();

        expect($subscription->starts_at)
            ->toBeInstanceOf(\Carbon\CarbonInterface::class);

        expect($subscription->current_period_end)
            ->toBeInstanceOf(\Carbon\CarbonInterface::class);
    }

    public function test_subscription_status_is_persisted(): void
    {
        $business = Business::factory()->create();

        $plan = SubscriptionPlan::create([
            'name' => 'Low',
            'slug' => 'low',
            'price' => 5000,
            'currency' => 'NGN',
            'billing_interval' => 'monthly',
            'is_active' => true,
        ]);

        $subscription = Subscription::create([
            'business_id' => $business->id,
            'plan_id' => $plan->id,
            'status' => 'grace_period',
        ]);

        $subscription->refresh();

        expect($subscription->status)
            ->toBe('grace_period');
    }

    public function test_subscription_can_store_provider_information(): void
    {
        $business = Business::factory()->create();

        $plan = SubscriptionPlan::create([
            'name' => 'Medium',
            'slug' => 'medium',
            'price' => 10000,
            'currency' => 'NGN',
            'billing_interval' => 'monthly',
            'is_active' => true,
        ]);

        $subscription = Subscription::create([
            'business_id' => $business->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'provider' => 'paystack',
            'provider_customer_code' => 'CUS_123456',
            'provider_subscription_code' => 'SUB_123456',
        ]);

        $subscription->refresh();

        expect($subscription->provider)
            ->toBe('paystack');

        expect($subscription->provider_customer_code)
            ->toBe('CUS_123456');

        expect($subscription->provider_subscription_code)
            ->toBe('SUB_123456');
    }

    public function test_subscription_has_many_payments(): void
    {
        $subscription = Subscription::factory()->create();

        $payment = Payment::factory()->create([
            'business_id' => $subscription->business_id,
            'subscription_id' => $subscription->id,
        ]);

        expect(
            $subscription->payments
                ->contains($payment)
        )->toBeTrue();
    }
}

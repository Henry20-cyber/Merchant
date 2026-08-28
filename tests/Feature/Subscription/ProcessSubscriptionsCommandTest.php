<?php

namespace Tests\Feature\Subscription;

use App\Domains\Organization\Models\Business;
use App\Domains\Subscription\Models\Subscription;
use App\Domains\Subscription\Models\SubscriptionPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcessSubscriptionsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function createPlan(): SubscriptionPlan
    {
        return SubscriptionPlan::factory()->create([
            'billing_interval' => 'monthly',
            'is_active' => true,
        ]);
    }

    private function createBusiness(): Business
    {
        return Business::factory()->create();
    }

    public function test_command_processes_expired_active_subscription(): void
    {
        $business = $this->createBusiness();
        $plan = $this->createPlan();

        $subscription = Subscription::factory()->create([
            'business_id' => $business->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'current_period_start' => now()->subMonth(),
            'current_period_end' => now()->subDay(),
        ]);

        $this->artisan('subscriptions:process')
            ->assertSuccessful();

        expect(
            $subscription->refresh()->status
        )->toBe('past_due');
    }

    public function test_command_processes_expired_grace_period(): void
    {
        $business = $this->createBusiness();
        $plan = $this->createPlan();

        $subscription = Subscription::factory()->create([
            'business_id' => $business->id,
            'plan_id' => $plan->id,
            'status' => 'grace_period',
            'current_period_end' => now()->subDays(10),
            'grace_period_ends_at' => now()->subDay(),
        ]);

        $this->artisan('subscriptions:process')
            ->assertSuccessful();

        $subscription->refresh();

        expect($subscription->status)
            ->toBe('restricted');

        expect($subscription->restricted_at)
            ->not->toBeNull();
    }

    public function test_command_processes_prolonged_restriction(): void
    {
        $business = $this->createBusiness();
        $plan = $this->createPlan();

        $subscription = Subscription::factory()->create([
            'business_id' => $business->id,
            'plan_id' => $plan->id,
            'status' => 'restricted',
            'restricted_at' => now()->subDays(31),
        ]);

        $this->artisan('subscriptions:process')
            ->assertSuccessful();

        expect(
            $subscription->refresh()->status
        )->toBe('suspended');
    }

    public function test_command_ignores_cancelled_subscriptions(): void
    {
        $business = $this->createBusiness();
        $plan = $this->createPlan();

        $subscription = Subscription::factory()->create([
            'business_id' => $business->id,
            'plan_id' => $plan->id,
            'status' => 'cancelled',
            'current_period_end' => now()->subDay(),
            'cancelled_at' => now()->subDay(),
        ]);

        $this->artisan('subscriptions:process')
            ->assertSuccessful();

        expect(
            $subscription->refresh()->status
        )->toBe('cancelled');
    }

    public function test_command_ignores_suspended_subscriptions(): void
    {
        $business = $this->createBusiness();
        $plan = $this->createPlan();

        $subscription = Subscription::factory()->create([
            'business_id' => $business->id,
            'plan_id' => $plan->id,
            'status' => 'suspended',
            'ended_at' => now()->subDay(),
        ]);

        $this->artisan('subscriptions:process')
            ->assertSuccessful();

        expect(
            $subscription->refresh()->status
        )->toBe('suspended');
    }

    public function test_command_is_safe_to_run_repeatedly(): void
    {
        $business = $this->createBusiness();
        $plan = $this->createPlan();

        $subscription = Subscription::factory()->create([
            'business_id' => $business->id,
            'plan_id' => $plan->id,
            'status' => 'grace_period',
            'current_period_end' => now()->subDays(10),
            'grace_period_ends_at' => now()->subDay(),
        ]);

        $this->artisan('subscriptions:process')
            ->assertSuccessful();

        $subscription->refresh();

        $restrictedAt = $subscription->restricted_at;

        $this->artisan('subscriptions:process')
            ->assertSuccessful();

        $subscription->refresh();

        expect($subscription->status)
            ->toBe('restricted');

        expect(
            $subscription->restricted_at
                ->equalTo($restrictedAt)
        )->toBeTrue();
    }
}
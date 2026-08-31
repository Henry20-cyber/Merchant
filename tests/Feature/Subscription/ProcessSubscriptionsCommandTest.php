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

    public function test_command_moves_expired_active_subscription_to_past_due(): void
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

    public function test_command_moves_past_due_subscription_to_grace_period(): void
    {
        $business = $this->createBusiness();
        $plan = $this->createPlan();

        $subscription = Subscription::factory()->create([
            'business_id' => $business->id,
            'plan_id' => $plan->id,
            'status' => 'past_due',
            'current_period_end' => now()->subDay(),
            'grace_period_ends_at' => null,
        ]);

        $this->artisan('subscriptions:process')
            ->assertSuccessful();

        $subscription->refresh();

        expect($subscription->status)
            ->toBe('grace_period');

        expect($subscription->grace_period_ends_at)
            ->not->toBeNull();

        expect(
            $subscription->grace_period_ends_at->isFuture()
        )->toBeTrue();
    }

    public function test_command_moves_expired_grace_period_to_restricted(): void
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

    public function test_command_moves_prolonged_restriction_to_suspended(): void
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

        $subscription->refresh();

        expect($subscription->status)
            ->toBe('suspended');

        expect($subscription->ended_at)
            ->not->toBeNull();
    }

    public function test_command_does_not_modify_active_subscription_with_future_period(): void
    {
        $business = $this->createBusiness();
        $plan = $this->createPlan();

        $subscription = Subscription::factory()->create([
            'business_id' => $business->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        $this->artisan('subscriptions:process')
            ->assertSuccessful();

        expect(
            $subscription->refresh()->status
        )->toBe('active');
    }

    public function test_command_does_not_modify_cancelled_subscription(): void
    {
        $business = $this->createBusiness();
        $plan = $this->createPlan();

        $subscription = Subscription::factory()->create([
            'business_id' => $business->id,
            'plan_id' => $plan->id,
            'status' => 'cancelled',
            'cancelled_at' => now()->subDay(),
            'current_period_end' => now()->subDay(),
        ]);

        $this->artisan('subscriptions:process')
            ->assertSuccessful();

        expect(
            $subscription->refresh()->status
        )->toBe('cancelled');
    }

    public function test_command_does_not_modify_suspended_subscription(): void
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

    public function test_command_processes_multiple_subscriptions(): void
    {
        $plan = $this->createPlan();

        $businessOne = $this->createBusiness();
        $businessTwo = $this->createBusiness();
        $businessThree = $this->createBusiness();

        $subscriptionOne = Subscription::factory()->create([
            'business_id' => $businessOne->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'current_period_end' => now()->subDay(),
        ]);

        $subscriptionTwo = Subscription::factory()->create([
            'business_id' => $businessTwo->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'current_period_end' => now()->subDay(),
        ]);

        $subscriptionThree = Subscription::factory()->create([
            'business_id' => $businessThree->id,
            'plan_id' => $plan->id,
            'status' => 'past_due',
            'current_period_end' => now()->subDay(),
        ]);

        $this->artisan('subscriptions:process')
            ->assertSuccessful();

        expect(
            $subscriptionOne->refresh()->status
        )->toBe('past_due');

        expect(
            $subscriptionTwo->refresh()->status
        )->toBe('past_due');

        expect(
            $subscriptionThree->refresh()->status
        )->toBe('grace_period');
    }

    public function test_command_reports_processed_count(): void
    {
        $plan = $this->createPlan();

        $business = $this->createBusiness();

        Subscription::factory()->create([
            'business_id' => $business->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'current_period_end' => now()->subDay(),
        ]);

        $this->artisan('subscriptions:process')
            ->expectsOutput(
                'Subscription lifecycle processing completed.'
            )
            ->expectsOutput('Processed: 1')
            ->expectsOutput('Failed: 0')
            ->assertSuccessful();
    }
}
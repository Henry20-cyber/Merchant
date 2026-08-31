<?php

namespace Tests\Feature\Subscription;

use App\Domains\Subscription\Models\SubscriptionPlan;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionPlanSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_subscription_plans_are_seeded(): void
    {
        $this->seed(SubscriptionPlanSeeder::class);

        expect(SubscriptionPlan::count())
            ->toBe(7);

        expect(
            SubscriptionPlan::whereIn('slug', [
                'free',
                'low-monthly',
                'low-yearly',
                'medium-monthly',
                'medium-yearly',
                'large-monthly',
                'large-yearly',
            ])->count()
        )->toBe(7);
    }

    public function test_low_monthly_plan_has_expected_configuration(): void
    {
        $this->seed(SubscriptionPlanSeeder::class);

        $plan = SubscriptionPlan::where(
            'slug',
            'low-monthly'
        )->firstOrFail();

        expect($plan->name)
            ->toBe('Low Monthly');

        expect($plan->price)
            ->toBe('5000.00');

        expect($plan->currency)
            ->toBe('NGN');

        expect($plan->billing_interval)
            ->toBe('monthly');

        expect($plan->customer_limit)
            ->toBe(50);

        expect($plan->user_limit)
            ->toBe(3);

        expect($plan->branch_limit)
            ->toBe(1);
    }

    public function test_low_yearly_plan_has_expected_configuration(): void
    {
        $this->seed(SubscriptionPlanSeeder::class);

        $plan = SubscriptionPlan::where(
            'slug',
            'low-yearly'
        )->firstOrFail();

        expect($plan->name)
            ->toBe('Low Yearly');

        expect($plan->price)
            ->toBe('50000.00');

        expect($plan->currency)
            ->toBe('NGN');

        expect($plan->billing_interval)
            ->toBe('yearly');

        expect($plan->customer_limit)
            ->toBe(50);

        expect($plan->user_limit)
            ->toBe(3);

        expect($plan->branch_limit)
            ->toBe(1);
    }

    public function test_medium_monthly_plan_has_expected_configuration(): void
    {
        $this->seed(SubscriptionPlanSeeder::class);

        $plan = SubscriptionPlan::where(
            'slug',
            'medium-monthly'
        )->firstOrFail();

        expect($plan->name)
            ->toBe('Medium Monthly');

        expect($plan->price)
            ->toBe('10000.00');

        expect($plan->billing_interval)
            ->toBe('monthly');

        expect($plan->customer_limit)
            ->toBe(100);

        expect($plan->user_limit)
            ->toBe(10);

        expect($plan->branch_limit)
            ->toBe(3);
    }

    public function test_medium_yearly_plan_has_expected_configuration(): void
    {
        $this->seed(SubscriptionPlanSeeder::class);

        $plan = SubscriptionPlan::where(
            'slug',
            'medium-yearly'
        )->firstOrFail();

        expect($plan->name)
            ->toBe('Medium Yearly');

        expect($plan->price)
            ->toBe('100000.00');

        expect($plan->billing_interval)
            ->toBe('yearly');

        expect($plan->customer_limit)
            ->toBe(100);

        expect($plan->user_limit)
            ->toBe(10);

        expect($plan->branch_limit)
            ->toBe(3);
    }

    public function test_large_monthly_plan_has_expected_configuration(): void
    {
        $this->seed(SubscriptionPlanSeeder::class);

        $plan = SubscriptionPlan::where(
            'slug',
            'large-monthly'
        )->firstOrFail();

        expect($plan->name)
            ->toBe('Large Monthly');

        expect($plan->price)
            ->toBe('20000.00');

        expect($plan->billing_interval)
            ->toBe('monthly');

        expect($plan->customer_limit)
            ->toBe(500);

        expect($plan->user_limit)
            ->toBe(50);

        expect($plan->branch_limit)
            ->toBe(10);
    }

    public function test_large_yearly_plan_has_expected_configuration(): void
    {
        $this->seed(SubscriptionPlanSeeder::class);

        $plan = SubscriptionPlan::where(
            'slug',
            'large-yearly'
        )->firstOrFail();

        expect($plan->name)
            ->toBe('Large Yearly');

        expect($plan->price)
            ->toBe('200000.00');

        expect($plan->billing_interval)
            ->toBe('yearly');

        expect($plan->customer_limit)
            ->toBe(500);

        expect($plan->user_limit)
            ->toBe(50);

        expect($plan->branch_limit)
            ->toBe(10);
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(SubscriptionPlanSeeder::class);
        $this->seed(SubscriptionPlanSeeder::class);

        expect(SubscriptionPlan::count())
            ->toBe(7);

        expect(
            SubscriptionPlan::whereIn('slug', [
                'low-monthly',
                'low-yearly',
                'medium-monthly',
                'medium-yearly',
                'large-monthly',
                'large-yearly',
            ])->count()
        )->toBe(6);
    }

    public function test_all_standard_plans_are_active(): void
    {
        $this->seed(SubscriptionPlanSeeder::class);

        expect(
            SubscriptionPlan::where('is_active', true)->count()
        )->toBe(7);
    }

    public function test_each_tier_has_monthly_and_yearly_plans(): void
    {
        $this->seed(SubscriptionPlanSeeder::class);

        foreach (
            [
                'low',
                'medium',
                'large',
            ] as $tier
        ) {
            expect(
                SubscriptionPlan::where(
                    'slug',
                    $tier . '-monthly'
                )->exists()
            )->toBeTrue();

            expect(
                SubscriptionPlan::where(
                    'slug',
                    $tier . '-yearly'
                )->exists()
            )->toBeTrue();
        }
    }

    public function test_receipt_entitlement_matches_plan_tier(): void
    {
        $this->seed(
            SubscriptionPlanSeeder::class
        );

        $expected = [
            'free' => false,

            'low-monthly' => false,
            'low-yearly' => false,

            'medium-monthly' => true,
            'medium-yearly' => true,

            'large-monthly' => true,
            'large-yearly' => true,
        ];

        foreach ($expected as $slug => $receiptsEnabled) {
            $plan = SubscriptionPlan::query()
                ->where('slug', $slug)
                ->firstOrFail();

            expect(
                $plan->features['receipts'] ?? false
            )->toBe($receiptsEnabled);
        }
    }
}

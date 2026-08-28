<?php

namespace Database\Factories\Domains\Subscription\Models;

use App\Domains\Subscription\Models\SubscriptionPlan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SubscriptionPlan>
 */
class SubscriptionPlanFactory extends Factory
{
    protected $model = SubscriptionPlan::class;

    public function definition(): array
    {
        return [
            'name' => 'Medium Monthly',
            'slug' => 'medium-monthly-' . Str::lower(Str::random(8)),
            'description' => 'Test subscription plan.',
            'price' => '10000.00',
            'currency' => 'NGN',
            'billing_interval' => 'monthly',
            'customer_limit' => 100,
            'user_limit' => 10,
            'branch_limit' => 3,
            'features' => [
                'pos' => true,
                'inventory' => true,
                'sales' => true,
                'customers' => true,
                'services' => true,
                'basic_reports' => true,
                'advanced_reports' => true,
            ],
            'paystack_plan_code' => null,

            'transaction_daily_limit' => 1000,
            'transaction_monthly_limit' => 10000,

            'is_active' => true,
        ];
    }
}

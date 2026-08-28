<?php

namespace Database\Seeders;

use App\Domains\Subscription\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

class SubscriptionPlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Low Monthly',
                'slug' => 'low-monthly',
                'description' => 'For small businesses getting started with MerchantOS.',
                'price' => 5000,
                'currency' => 'NGN',
                'billing_interval' => 'monthly',
                'transaction_daily_limit' => 100,
                'transaction_monthly_limit' => 1000,
                'customer_limit' => 50,
                'user_limit' => 3,
                'branch_limit' => 1,
                'features' => [
                    'pos' => true,
                    'inventory' => true,
                    'sales' => true,
                    'customers' => true,
                    'services' => true,
                    'basic_reports' => true,
                    'advanced_reports' => false,
                ],
                'paystack_plan_code' => null,
                'is_active' => true,
            ],

            [
                'name' => 'Low Yearly',
                'slug' => 'low-yearly',
                'description' => 'Annual plan for small businesses with two months free.',
                'price' => 50000,
                'currency' => 'NGN',
                'billing_interval' => 'yearly',
                'transaction_daily_limit' => 100,
                'transaction_monthly_limit' => 1000,
                'customer_limit' => 50,
                'user_limit' => 3,
                'branch_limit' => 1,
                'features' => [
                    'pos' => true,
                    'inventory' => true,
                    'sales' => true,
                    'customers' => true,
                    'services' => true,
                    'basic_reports' => true,
                    'advanced_reports' => false,
                ],
                'paystack_plan_code' => null,
                'is_active' => true,
            ],

            [
                'name' => 'Medium Monthly',
                'slug' => 'medium-monthly',
                'description' => 'For growing businesses that need more capacity.',
                'price' => 10000,
                'currency' => 'NGN',
                'billing_interval' => 'monthly',
                'transaction_daily_limit' => 500,
                'transaction_monthly_limit' => 5000,
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
                'is_active' => true,
            ],

            [
                'name' => 'Medium Yearly',
                'slug' => 'medium-yearly',
                'description' => 'Annual plan for growing businesses with two months free.',
                'price' => 100000,
                'currency' => 'NGN',
                'billing_interval' => 'yearly',
                'transaction_daily_limit' => 500,
                'transaction_monthly_limit' => 5000,
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
                'is_active' => true,
            ],

            [
                'name' => 'Large Monthly',
                'slug' => 'large-monthly',
                'description' => 'For established businesses operating at larger scale.',
                'price' => 20000,
                'currency' => 'NGN',
                'billing_interval' => 'monthly',
                'transaction_daily_limit' => 500,
                'transaction_monthly_limit' => 5000,
                'customer_limit' => 500,
                'user_limit' => 50,
                'branch_limit' => 10,
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
                'is_active' => true,
            ],

            [
                'name' => 'Large Yearly',
                'slug' => 'large-yearly',
                'description' => 'Annual plan for established businesses with two months free.',
                'price' => 200000,
                'currency' => 'NGN',
                'billing_interval' => 'yearly',
                'transaction_daily_limit' => 500,
                'transaction_monthly_limit' => 5000,
                'customer_limit' => 500,
                'user_limit' => 50,
                'branch_limit' => 10,
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
                'is_active' => true,
            ],
        ];

        foreach ($plans as $plan) {
            SubscriptionPlan::updateOrCreate(
                [
                    'slug' => $plan['slug'],
                ],
                $plan
            );
        }
    }
}

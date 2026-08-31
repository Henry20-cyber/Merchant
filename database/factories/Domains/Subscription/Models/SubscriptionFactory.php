<?php

namespace Database\Factories\Domains\Subscription\Models;

use App\Domains\Organization\Models\Business;
use App\Domains\Subscription\Models\Subscription;
use App\Domains\Subscription\Models\SubscriptionPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'plan_id' => SubscriptionPlan::factory(),
            'status' => 'trial',
            'provider' => null,
            'provider_customer_code' => null,
            'provider_authorization_code' => null,
            'provider_subscription_code' => null,
            'starts_at' => now(),
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
            'grace_period_ends_at' => null,
            'cancelled_at' => null,
            'ended_at' => null,
        ];
    }
}

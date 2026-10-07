<?php

namespace Tests\Support;

use App\Domains\Organization\Models\Business;
use App\Domains\Subscription\Models\Subscription;
use App\Domains\Subscription\Models\SubscriptionPlan;

trait CreatesSubscriptionForBusiness
{
    protected function createBusinessWithSubscription(
        array $planOverrides = [],
        array $subscriptionOverrides = [],
    ): Business {
        $business = Business::factory()->create();

        $plan = SubscriptionPlan::factory()->create(array_merge([
            'is_active' => true,
        ], $planOverrides));

        Subscription::factory()->create(array_merge([
            'business_id' => $business->id,
            'plan_id' => $plan->id,
            'status' => 'trial',
            'starts_at' => now(),
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
            'grace_period_ends_at' => null,
        ], $subscriptionOverrides));

        return $business;
    }
}

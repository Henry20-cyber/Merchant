<?php

namespace App\Domains\Subscription\Services;

use App\Domains\Subscription\Models\Subscription;
use Illuminate\Validation\ValidationException;

class SubscriptionCapabilityService
{
    /**
     * Determine whether a subscription grants a capability.
     *
     * A capability is granted only when:
     *
     * 1. The subscription currently allows access.
     * 2. The subscription has an active plan.
     * 3. The plan explicitly enables the capability.
     */
    public function allows(
        Subscription $subscription,
        string $capability
    ): bool {
        $subscriptionService = app(
            SubscriptionService::class
        );

        /*
         * Inactive/expired subscriptions cannot use
         * subscription-gated capabilities.
         */
        if (! $subscriptionService->isUsable($subscription)) {
            return false;
        }

        $plan = $subscription->plan;

        /*
         * A subscription without a valid active plan
         * cannot grant capabilities.
         */
        if (! $plan || ! $plan->is_active) {
            return false;
        }

        /*
         * Features are stored as JSONB and cast to an array
         * by SubscriptionPlan.
         */
        $features = $plan->features ?? [];

        return (bool) (
            $features[$capability] ?? false
        );
    }

    /**
     * Require a capability.
     *
     * Throws a validation exception when the subscription
     * does not grant the requested capability.
     */
    public function require(
        Subscription $subscription,
        string $capability
    ): void {
        if ($this->allows(
            $subscription,
            $capability
        )) {
            return;
        }

        throw ValidationException::withMessages([
            'subscription' =>
                'Your current subscription plan does not include this feature.',
        ]);
    }
}

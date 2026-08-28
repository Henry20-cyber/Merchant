<?php

namespace App\Domains\Subscription\Services;

use App\Domains\Organization\Models\Business;
use App\Domains\Subscription\Models\Subscription;
use App\Domains\Subscription\Models\SubscriptionPlan;
use Illuminate\Validation\ValidationException;

class SubscriptionService
{
    /**
     * Create a trial subscription for a business.
     */
    public function createTrial(
        Business $business,
        SubscriptionPlan $plan,
        int $trialDays = 7
    ): Subscription {
        if ($trialDays < 1) {
            throw ValidationException::withMessages([
                'trial_days' => 'Trial period must be at least one day.',
            ]);
        }

        if (! $plan->is_active) {
            throw ValidationException::withMessages([
                'plan' => 'The selected subscription plan is not active.',
            ]);
        }

        if ($business->subscription()->exists()) {
            throw ValidationException::withMessages([
                'subscription' => 'This business already has a subscription.',
            ]);
        }

        $startsAt = now();

        $trialEndsAt = $startsAt
            ->copy()
            ->addDays($trialDays);

        return Subscription::create([
            'business_id' => $business->id,

            // IMPORTANT:
            // The subscriptions table uses plan_id.
            'plan_id' => $plan->id,

            'status' => 'trial',
            'starts_at' => $startsAt,
            'current_period_start' => $startsAt,
            'current_period_end' => $trialEndsAt,
        ]);
    }

    /**
     * Activate a subscription.
     */
    public function activate(
        Subscription $subscription
    ): Subscription {
        $this->ensurePlanIsActive($subscription);

        $subscription->forceFill([
            'status' => 'active',
        ])->save();

        return $subscription->refresh();
    }

    /**
     * Mark a subscription as past due.
     */
    public function markPastDue(
        Subscription $subscription
    ): Subscription {
        $subscription->forceFill([
            'status' => 'past_due',
        ])->save();

        return $subscription->refresh();
    }

    /**
     * Put a subscription into its grace period.
     */
    public function enterGracePeriod(
        Subscription $subscription,
        int $graceDays = 7
    ): Subscription {
        if ($graceDays < 1) {
            throw ValidationException::withMessages([
                'grace_days' => 'Grace period must be at least one day.',
            ]);
        }

        $gracePeriodEndsAt = now()
            ->copy()
            ->addDays($graceDays);

        $subscription->forceFill([
            'status' => 'grace_period',
            'grace_period_ends_at' => $gracePeriodEndsAt,
        ])->save();

        return $subscription->refresh();
    }

    /**
     * Restrict a subscription after the grace period.
     */
    public function restrict(
        Subscription $subscription
    ): Subscription {
        $subscription->forceFill([
            'status' => 'restricted',
        ])->save();

        return $subscription->refresh();
    }

    /**
     * Suspend a subscription.
     */
    public function suspend(
        Subscription $subscription
    ): Subscription {
        $subscription->forceFill([
            'status' => 'suspended',
        ])->save();

        return $subscription->refresh();
    }

    /**
     * Change the subscription plan.
     */
    public function changePlan(
        Subscription $subscription,
        SubscriptionPlan $plan
    ): Subscription {
        if (! $plan->is_active) {
            throw ValidationException::withMessages([
                'plan' => 'The selected subscription plan is not active.',
            ]);
        }

        $subscription->forceFill([
            'plan_id' => $plan->id,
        ])->save();

        return $subscription->refresh();
    }

    /**
     * Determine whether the subscription currently grants access.
     */
    public function isUsable(
        Subscription $subscription
    ): bool {
        if (! in_array(
            $subscription->status,
            [
                'trial',
                'active',
                'grace_period',
            ],
            true
        )) {
            return false;
        }

        if (
            $subscription->status === 'grace_period' &&
            $subscription->grace_period_ends_at !== null &&
            $subscription->grace_period_ends_at->isPast()
        ) {
            return false;
        }

        if (
            $subscription->status !== 'grace_period' &&
            $subscription->current_period_end !== null &&
            $subscription->current_period_end->isPast()
        ) {
            return false;
        }

        return true;
    }

    /**
     * Ensure the subscription's plan is active.
     */
    private function ensurePlanIsActive(
        Subscription $subscription
    ): void {
        $plan = $subscription->plan;

        if (! $plan || ! $plan->is_active) {
            throw ValidationException::withMessages([
                'plan' => 'The subscription plan is not active.',
            ]);
        }
    }
}
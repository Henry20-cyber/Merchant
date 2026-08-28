<?php

namespace App\Domains\Subscription\Services;

use App\Domains\Subscription\Models\Subscription;
use Illuminate\Support\Facades\DB;

class SubscriptionLifecycleService
{
    /**
     * Number of days a restricted subscription remains
     * recoverable before being suspended.
     */
    private const RESTRICTION_DAYS = 30;

    /**
     * Number of days granted during the grace period.
     */
    private const GRACE_PERIOD_DAYS = 7;

    /**
     * Process the lifecycle of a single subscription.
     *
     * This method deliberately handles one subscription at a time.
     * The scheduled command can later retrieve subscriptions and
     * pass each one through this method.
     */
    public function process(Subscription $subscription): Subscription
    {
        return DB::transaction(function () use ($subscription) {
            /*
             * Always work with the latest database state.
             */
            $subscription->refresh();

            /*
             * Terminal states require no lifecycle processing.
             */
            if (in_array(
                $subscription->status,
                [
                    'cancelled',
                    'suspended',
                ],
                true
            )) {
                return $subscription;
            }

            /*
             * ---------------------------------------------------------
             * TRIAL
             * ---------------------------------------------------------
             *
             * An expired trial becomes past_due.
             */
            if ($subscription->status === 'trial') {
                if (
                    $subscription->current_period_end !== null &&
                    $subscription->current_period_end->isPast()
                ) {
                    $subscription->forceFill([
                        'status' => 'past_due',
                    ])->save();
                }

                return $subscription->refresh();
            }

            /*
             * ---------------------------------------------------------
             * ACTIVE
             * ---------------------------------------------------------
             *
             * An expired billing period becomes past_due.
             *
             * We do not automatically create a new subscription period
             * here. Renewal/payment confirmation is responsible for
             * extending the period after successful payment.
             */
            if ($subscription->status === 'active') {
                if (
                    $subscription->current_period_end !== null &&
                    $subscription->current_period_end->isPast()
                ) {
                    $subscription->forceFill([
                        'status' => 'past_due',
                    ])->save();
                }

                return $subscription->refresh();
            }

            /*
             * ---------------------------------------------------------
             * PAST DUE
             * ---------------------------------------------------------
             *
             * Start the grace period.
             */
            if ($subscription->status === 'past_due') {
                $subscription->forceFill([
                    'status' => 'grace_period',
                    'grace_period_ends_at' => now()->addDays(
                        self::GRACE_PERIOD_DAYS
                    ),
                ])->save();

                return $subscription->refresh();
            }

            /*
             * ---------------------------------------------------------
             * GRACE PERIOD
             * ---------------------------------------------------------
             *
             * If the grace period has expired, restrict the account.
             */
            if ($subscription->status === 'grace_period') {
                if (
                    $subscription->grace_period_ends_at !== null &&
                    $subscription->grace_period_ends_at->isPast()
                ) {
                    /*
                     * Do not overwrite restricted_at if another process
                     * has already restricted this subscription.
                     */
                    if ($subscription->restricted_at === null) {
                        $subscription->forceFill([
                            'status' => 'restricted',
                            'restricted_at' => now(),
                        ])->save();
                    }
                }

                return $subscription->refresh();
            }

            /*
             * ---------------------------------------------------------
             * RESTRICTED
             * ---------------------------------------------------------
             *
             * After the recovery window expires, suspend the
             * subscription.
             */
            if ($subscription->status === 'restricted') {
                /*
                 * A legacy/invalid restricted record without
                 * restricted_at cannot safely be timed.
                 *
                 * Leave it restricted until the timestamp exists.
                 */
                if ($subscription->restricted_at === null) {
                    return $subscription;
                }

                if (
                    $subscription->restricted_at
                        ->copy()
                        ->addDays(self::RESTRICTION_DAYS)
                        ->isPast()
                ) {
                    $subscription->forceFill([
                        'status' => 'suspended',
                        'ended_at' => now(),
                    ])->save();
                }

                return $subscription->refresh();
            }

            /*
             * Unknown statuses are deliberately left untouched.
             *
             * This is safer than making assumptions about future
             * subscription states.
             */
            return $subscription;
        });
    }
}

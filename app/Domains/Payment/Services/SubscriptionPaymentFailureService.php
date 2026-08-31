<?php

namespace App\Domains\Payment\Services;

use App\Domains\Subscription\Models\Subscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubscriptionPaymentFailureService
{
    /**
     * Process a failed recurring subscription payment.
     *
     * A failed automatic payment moves the subscription to
     * past_due. The SubscriptionLifecycleService is responsible
     * for subsequently moving it through grace_period,
     * restricted, and suspended states.
     *
     * This operation is intentionally idempotent.
     */
    public function fail(array $data): Subscription
    {
        return DB::transaction(function () use ($data) {
            $customerCode = data_get(
                $data,
                'customer_code'
            );

            $subscriptionCode = data_get(
                $data,
                'subscription_code'
            );

            if (! $customerCode) {
                throw ValidationException::withMessages([
                    'customer' =>
                        'Paystack customer code is missing.',
                ]);
            }

            if (! $subscriptionCode) {
                throw ValidationException::withMessages([
                    'subscription' =>
                        'Paystack subscription code is missing.',
                ]);
            }

            /*
             * Locate the MerchantOS subscription using the same
             * provider identifiers used by successful renewals.
             */
            $subscription = Subscription::query()
                ->where(
                    'provider',
                    'paystack'
                )
                ->where(
                    'provider_customer_code',
                    $customerCode
                )
                ->where(
                    'provider_subscription_code',
                    $subscriptionCode
                )
                ->lockForUpdate()
                ->first();

            if (! $subscription) {
                throw ValidationException::withMessages([
                    'subscription' =>
                        'MerchantOS subscription could not be matched to the Paystack subscription.',
                ]);
            }

            /*
             * Terminal states should not be moved backwards.
             */
            if (in_array(
                $subscription->status,
                [
                    'cancelled',
                    'suspended',
                ],
                true
            )) {
                return $subscription->refresh();
            }

            /*
             * A failed payment means the subscription is now
             * past due.
             *
             * Do not set grace_period here.
             *
             * SubscriptionLifecycleService owns the transition:
             *
             * past_due → grace_period
             */
            $subscription->forceFill([
                'status' => 'past_due',

                /*
                 * A new failed billing attempt starts a new
                 * lifecycle cycle, so clear any old grace period.
                 */
                'grace_period_ends_at' => null,
            ])->save();

            return $subscription->refresh();
        });
    }
}
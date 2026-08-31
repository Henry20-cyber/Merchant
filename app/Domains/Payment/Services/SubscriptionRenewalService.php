<?php

namespace App\Domains\Payment\Services;

use App\Domains\Payment\Models\Payment;
use App\Domains\Subscription\Models\Subscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubscriptionRenewalService
{
    /**
     * Process a successful recurring Paystack charge.
     *
     * This method is idempotent. A repeated webhook for the
     * same Paystack reference will never extend the subscription twice.
     */
    public function renew(array $data): Payment
    {
        return DB::transaction(function () use ($data) {
            $reference = $data['reference'] ?? null;

            if (! $reference) {
                throw ValidationException::withMessages([
                    'reference' => 'Payment reference is required.',
                ]);
            }

            /*
             * Idempotency:
             *
             * If this exact Paystack transaction has already been
             * recorded, return it without extending the subscription.
             */
            $existingPayment = Payment::query()
                ->where('reference', $reference)
                ->lockForUpdate()
                ->first();

            if ($existingPayment) {
                return $existingPayment->refresh();
            }

            $customerCode =
                data_get($data, 'customer_code');

            $subscriptionCode =
                data_get($data, 'subscription_code');

            $amount =
                data_get($data, 'amount');

            $currency =
                strtoupper(
                    (string) data_get(
                        $data,
                        'currency',
                        ''
                    )
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
             * Find the existing MerchantOS subscription using
             * Paystack's customer + subscription identifiers.
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

            $plan = $subscription->plan;

            if (! $plan || ! $plan->is_active) {
                throw ValidationException::withMessages([
                    'plan' =>
                        'The subscription plan is not active.',
                ]);
            }

            /*
             * Paystack sends amounts in minor units.
             */
            $expectedAmount = $this->toMinorUnits(
                (string) $plan->price
            );

            if ((int) $amount !== $expectedAmount) {
                throw ValidationException::withMessages([
                    'amount' =>
                        'Recurring payment amount does not match the subscription plan.',
                ]);
            }

            $expectedCurrency = strtoupper(
                (string) $plan->currency
            );

            if ($currency !== $expectedCurrency) {
                throw ValidationException::withMessages([
                    'currency' =>
                        'Recurring payment currency does not match the subscription plan.',
                ]);
            }

            /*
             * Create the renewal payment.
             *
             * This is not a POS sale, hence sale_id = null.
             */
            $payment = Payment::create([
                'business_id' =>
                    $subscription->business_id,

                'sale_id' => null,

                'subscription_id' =>
                    $subscription->id,

                'amount' =>
                    $plan->price,

                'method' => 'paystack',

                'status' => 'paid',

                'reference' =>
                    $reference,

                'metadata' => [
                    'type' => 'subscription_renewal',

                    'provider' => 'paystack',

                    'customer_code' =>
                        $customerCode,

                    'subscription_code' =>
                        $subscriptionCode,

                    'plan_id' =>
                        $plan->id,
                ],

                'paid_at' =>
                    data_get(
                        $data,
                        'paid_at'
                    ) ?? now(),
            ]);

            /*
             * Establish the next billing period.
             *
             * If the previous period is still in the future,
             * extend from its end rather than from "now".
             *
             * This prevents customers from losing unused time
             * if Paystack sends the webhook slightly early/late.
             */
            $periodStart =
                $subscription->current_period_end &&
                $subscription->current_period_end->isFuture()
                    ? $subscription->current_period_end->copy()
                    : now();

            $periodEnd =
                $plan->billing_interval === 'yearly'
                    ? $periodStart->copy()->addYear()
                    : $periodStart->copy()->addMonth();

            /*
             * Successful renewal restores access regardless of
             * whether the subscription had entered past_due,
             * grace_period, or restricted state.
             */
            $subscription->forceFill([
                'status' => 'active',

                'current_period_start' =>
                    $periodStart,

                'current_period_end' =>
                    $periodEnd,

                'grace_period_ends_at' =>
                    null,

                'restricted_at' =>
                    null,

                'cancelled_at' =>
                    null,

                'ended_at' =>
                    null,
            ])->save();

            return $payment->refresh();
        });
    }

    /**
     * Convert major currency units into provider minor units.
     */
    private function toMinorUnits(string $amount): int
    {
        $normalized = number_format(
            (float) $amount,
            2,
            '.',
            ''
        );

        [$whole, $decimal] = array_pad(
            explode('.', $normalized, 2),
            2,
            '00'
        );

        return (
            ((int) $whole * 100)
            +
            (int) str_pad(
                substr($decimal, 0, 2),
                2,
                '0'
            )
        );
    }
}
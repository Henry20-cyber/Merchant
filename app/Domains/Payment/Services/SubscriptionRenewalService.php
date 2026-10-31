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

            // Paystack expresses amounts in minor units. Accept only an
            // integer or a digit-only integer string; PHP's loose integer
            // cast would otherwise accept malformed values such as "12000x".
            if (
                ! (is_int($amount) || (is_string($amount) && preg_match('/\A\d+\z/D', $amount) === 1)) ||
                (int) $amount !== $expectedAmount
            ) {
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
             * A provider may deliver a delayed successful charge after the
             * customer cancelled or the subscription was suspended. Record
             * the real payment above, but never silently resurrect a terminal
             * subscription or turn auto-renew back on.
             */
            if (in_array($subscription->status, ['cancelled', 'suspended'], true)) {
                return $payment->refresh();
            }

            /*
             * Establish the next billing period.
             *
             * Always advance from the previous scheduled period end
             * when it exists. A delayed webhook must not shift the
             * customer's billing anniversary.
             */
            $periodStart = $subscription->current_period_end
                ? $subscription->current_period_end->copy()
                : now();

            $periodEnd = app(\App\Domains\Subscription\Services\SubscriptionPeriodCalculator::class)
                ->endFor($periodStart, $plan->billing_interval);

            /*
             * Successful renewal restores access regardless of
             * whether the subscription had entered past_due,
             * grace_period, or restricted state.
             */
            $renewalEnabled = (bool) $subscription->auto_renew;

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

                // A successful charge pays for the next period; it does not
                // revoke a customer's previously recorded non-renewal choice.
                'cancelled_at' => $renewalEnabled
                    ? null
                    : $subscription->cancelled_at,

                'ended_at' =>
                    null,

                'auto_renew' => $renewalEnabled,
            ])->save();

            return $payment->refresh();
        });
    }

    /**
     * Convert major currency units into provider minor units.
     */
    private function toMinorUnits(string $amount): int
    {
        $amount = trim($amount);

        if (! preg_match('/^(\\d+)(?:\\.(\\d{1,2}))?$/', $amount, $matches)) {
            throw ValidationException::withMessages([
                'amount' => 'The subscription plan amount is invalid.',
            ]);
        }

        $whole = (int) $matches[1];
        $fraction = (int) str_pad($matches[2] ?? '', 2, '0');

        return ($whole * 100) + $fraction;
    }
}
<?php

namespace App\Domains\Payment\Services;

use App\Domains\Payment\Contracts\PaymentGateway;
use App\Domains\Payment\Models\Payment;
use App\Domains\Subscription\Models\Subscription;
use App\Domains\Subscription\Models\SubscriptionPlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentConfirmationService
{
    public function __construct(
        private PaymentGateway $paymentGateway,
    ) {
    }

    /**
     * Confirm a Paystack payment and fulfil it.
     *
     * This method is idempotent:
     * processing the same successful payment twice
     * must not create duplicate business value.
     */
    public function confirm(string $reference): Payment
    {
        /*
         * Find the MerchantOS payment first.
         */
        $payment = Payment::query()
            ->where('reference', $reference)
            ->first();

        if (! $payment) {
            throw ValidationException::withMessages([
                'reference' => 'Payment reference was not found.',
            ]);
        }

        /*
         * Idempotency.
         *
         * If MerchantOS already processed this payment,
         * there is nothing else to fulfil.
         */
        if ($payment->status === 'paid') {
            return $payment;
        }

        /*
         * Ask Paystack for the authoritative transaction state.
         *
         * Never trust the webhook payload alone for payment value.
         */
        $verified = $this->paymentGateway->verify(
            $reference
        );

        /*
         * Paystack transaction must actually be successful.
         */
        if (
            ! $verified['success'] ||
            $verified['status'] !== 'success'
        ) {
            $this->markFailedIfAppropriate($payment);

            throw ValidationException::withMessages([
                'payment' => 'The Paystack transaction was not successful.',
            ]);
        }

        /*
         * Verify the reference returned by Paystack.
         */
        if (
            $verified['reference'] !== null &&
            $verified['reference'] !== $payment->reference
        ) {
            throw ValidationException::withMessages([
                'payment' => 'Payment reference verification failed.',
            ]);
        }

        /*
         * Verify the amount.
         *
         * MerchantOS stores NGN as decimal naira.
         * Paystack returns the amount in kobo.
         */
        $expectedAmount = $this->toMinorUnits(
            (string) $payment->amount
        );

        if (
            $verified['amount'] === null ||
            $verified['amount'] !== $expectedAmount
        ) {
            throw ValidationException::withMessages([
                'payment' => 'Payment amount verification failed.',
            ]);
        }

        /*
         * Verify currency.
         */
        if (
            $verified['currency'] !== null &&
            strtoupper($verified['currency']) !== 'NGN'
        ) {
            throw ValidationException::withMessages([
                'payment' => 'Payment currency verification failed.',
            ]);
        }

        /*
         * Fulfil the payment atomically.
         */
        return DB::transaction(function () use ($payment) {
            /*
             * Lock this payment row.
             *
             * This protects us if Paystack sends the same
             * webhook more than once concurrently.
             */
            $lockedPayment = Payment::query()
                ->whereKey($payment->id)
                ->lockForUpdate()
                ->firstOrFail();

            /*
             * Another webhook may have completed it while
             * this request was verifying with Paystack.
             */
            if ($lockedPayment->status === 'paid') {
                return $lockedPayment->refresh();
            }

            $lockedPayment->forceFill([
                'status' => 'paid',
                'paid_at' => now(),
            ])->save();

            /*
             * Subscription payments require additional fulfilment.
             */
            if (
                data_get(
                    $lockedPayment->metadata,
                    'type'
                ) === 'subscription'
            ) {
                $this->fulfilSubscriptionPayment(
                    $lockedPayment
                );
            }

            return $lockedPayment->refresh();
        });
    }

    /**
     * Fulfil a successful subscription payment.
     */
    private function fulfilSubscriptionPayment(
        Payment $payment
    ): void {
        $metadata = $payment->metadata ?? [];

        $businessId = $metadata['business_id'] ?? null;
        $planId = $metadata['plan_id'] ?? null;

        if (! $businessId || ! $planId) {
            throw ValidationException::withMessages([
                'payment' => 'Subscription payment metadata is incomplete.',
            ]);
        }

        /*
         * The plan must still exist and remain active.
         */
        $plan = SubscriptionPlan::query()
            ->whereKey($planId)
            ->where('is_active', true)
            ->first();

        if (! $plan) {
            throw ValidationException::withMessages([
                'plan' => 'The subscription plan is no longer available.',
            ]);
        }

        /*
         * Lock the existing subscription if one exists.
         *
         * This is important for trial → paid transitions
         * and future renewals.
         */
        $subscription = Subscription::query()
            ->where('business_id', $businessId)
            ->lockForUpdate()
            ->first();

        $now = now();

        $periodEnd = $plan->billing_interval === 'yearly'
            ? $now->copy()->addYear()
            : $now->copy()->addMonth();

        if (! $subscription) {
            /*
             * Brand-new business:
             *
             * Payment succeeds first.
             * Subscription is created only now.
             */
            $subscription = Subscription::create([
                'business_id' => $businessId,
                'plan_id' => $plan->id,
                'status' => 'active',
                'provider' => 'paystack',
                'starts_at' => $now,
                'current_period_start' => $now,
                'current_period_end' => $periodEnd,
            ]);
        } else {
            /*
             * Existing trial/past-due/grace subscription:
             * turn it into an active paid subscription.
             */
            $subscription->forceFill([
                'plan_id' => $plan->id,
                'status' => 'active',
                'provider' => 'paystack',
                'current_period_start' => $now,
                'current_period_end' => $periodEnd,
                'grace_period_ends_at' => null,
                'cancelled_at' => null,
                'ended_at' => null,
            ])->save();

            $subscription->refresh();
        }

        /*
         * Link the payment to the subscription that was
         * actually fulfilled.
         */
        $payment->forceFill([
            'subscription_id' => $subscription->id,
        ])->save();
    }

    private function markFailedIfAppropriate(
        Payment $payment
    ): void {
        /*
         * Only pending payments are transitioned to failed.
         *
         * We don't overwrite historical paid/refunded/etc.
         * states.
         */
        if ($payment->status === 'pending') {
            $payment->forceFill([
                'status' => 'failed',
            ])->save();
        }
    }

    /**
     * Convert naira to kobo without floating point arithmetic.
     */
    private function toMinorUnits(string $amount): int
    {
        $parts = explode('.', $amount, 2);

        $naira = $parts[0] ?? '0';

        $kobo = str_pad(
            $parts[1] ?? '0',
            2,
            '0'
        );

        $kobo = substr($kobo, 0, 2);

        return ((int) $naira * 100) + (int) $kobo;
    }
}

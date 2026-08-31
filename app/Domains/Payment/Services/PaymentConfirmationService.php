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
        private PaymentGateway $paymentGateway
    ) {
    }

    /**
     * Confirm a payment using the payment provider.
     *
     * The operation is idempotent:
     * an already-paid payment is never fulfilled twice.
     */
    public function confirm(string $reference): Payment
    {
        return DB::transaction(function () use ($reference) {
            /*
             * Lock the payment so two simultaneous requests
             * cannot fulfil the same payment concurrently.
             */
            $payment = Payment::query()
                ->where('reference', $reference)
                ->lockForUpdate()
                ->first();

            if (! $payment) {
                throw ValidationException::withMessages([
                    'reference' => 'Payment not found.',
                ]);
            }

            /*
             * Idempotency.
             *
             * If this payment was already successfully processed,
             * simply return it.
             */
            if ($payment->status === 'paid') {
                return $payment->refresh();
            }

            /*
             * Only pending payments may be confirmed.
             */
            if ($payment->status !== 'pending') {
                throw ValidationException::withMessages([
                    'payment' => 'Only pending payments can be confirmed.',
                ]);
            }

            /*
             * Ask Paystack for the authoritative transaction state.
             */
            $verified = $this->paymentGateway->verify(
                $payment->reference
            );

            /*
             * A provider-reported failed transaction is an expected
             * payment outcome, not an application exception.
             *
             * Persist the failed state and commit the transaction.
             */
            if (
                ! ($verified['success'] ?? false) ||
                ($verified['status'] ?? null) !== 'success'
            ) {
                $payment->forceFill([
                    'status' => 'failed',
                ])->save();

                return $payment->refresh();
            }

            /*
             * The verified reference must match our local payment.
             */
            if (
                ($verified['reference'] ?? null) !==
                $payment->reference
            ) {
                throw ValidationException::withMessages([
                    'reference' => 'Payment reference mismatch.',
                ]);
            }

            /*
             * MerchantOS stores amounts in major currency units.
             * Paystack returns amounts in minor units.
             */
            $expectedAmount = $this->toMinorUnits(
                (string) $payment->amount
            );

            if (
                ($verified['amount'] ?? null) !==
                $expectedAmount
            ) {
                throw ValidationException::withMessages([
                    'amount' => 'Payment amount does not match.',
                ]);
            }

            /*
             * Subscription currency is checked against the plan
             * during subscription fulfilment.
             *
             * For non-subscription payments, use payment metadata
             * when available.
             */
            if (
                ($payment->metadata['type'] ?? null) !==
                'subscription'
            ) {
                $expectedCurrency = strtoupper(
                    (string) (
                        $payment->metadata['currency']
                        ?? 'NGN'
                    )
                );

                $verifiedCurrency = strtoupper(
                    (string) (
                        $verified['currency']
                        ?? ''
                    )
                );

                if (
                    $verifiedCurrency !== '' &&
                    $verifiedCurrency !== $expectedCurrency
                ) {
                    throw ValidationException::withMessages([
                        'currency' => 'Payment currency does not match.',
                    ]);
                }
            }

            /*
             * Mark payment as paid.
             */
            $payment->forceFill([
                'status' => 'paid',
                'paid_at' => now(),
            ])->save();

            /*
             * Subscription payments require recurring-billing
             * fulfilment.
             */
            if (
                ($payment->metadata['type'] ?? null) ===
                'subscription'
            ) {
                $this->fulfilSubscriptionPayment(
                    $payment,
                    $verified
                );
            }

            return $payment->refresh();
        });
    }

    /**
     * Fulfil a successful subscription payment.
     */
    private function fulfilSubscriptionPayment(
        Payment $payment,
        array $verified
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
         * Load the authoritative subscription plan.
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
         * The verified Paystack currency must match
         * the MerchantOS plan currency.
         */
        $verifiedCurrency = strtoupper(
            (string) ($verified['currency'] ?? '')
        );

        $planCurrency = strtoupper(
            (string) $plan->currency
        );

        if ($verifiedCurrency !== $planCurrency) {
            throw ValidationException::withMessages([
                'currency' => 'Payment currency does not match the subscription plan.',
            ]);
        }

        /*
         * A recurring subscription requires a provider plan code.
         */
        if (! $plan->paystack_plan_code) {
            throw ValidationException::withMessages([
                'plan' => 'The subscription plan is not configured for recurring billing.',
            ]);
        }

        /*
         * Paystack must provide the authorization information
         * necessary for future automatic charges.
         */
        $customerCode = $verified['customer_code'] ?? null;
        $authorizationCode = $verified['authorization_code'] ?? null;

        if (! $customerCode || ! $authorizationCode) {
            throw ValidationException::withMessages([
                'payment' => 'Paystack authorization information is incomplete.',
            ]);
        }

        /*
         * Lock the existing subscription.
         *
         * This is particularly important for trial -> paid
         * transitions and concurrent callbacks.
         */
        $subscription = Subscription::query()
            ->where('business_id', $businessId)
            ->lockForUpdate()
            ->first();

        /*
         * If recurring billing has already been established,
         * do not create another provider subscription.
         */
        if (
            $subscription &&
            $subscription->provider_subscription_code
        ) {
            $payment->forceFill([
                'subscription_id' => $subscription->id,
            ])->save();

            return;
        }

        $now = now();

        $periodEnd = $plan->billing_interval === 'yearly'
            ? $now->copy()->addYear()
            : $now->copy()->addMonth();

        /*
         * Create the MerchantOS subscription if the business
         * does not have one yet.
         */
        if (! $subscription) {
            $subscription = Subscription::create([
                'business_id' => $businessId,
                'plan_id' => $plan->id,
                'status' => 'active',
                'provider' => 'paystack',

                'provider_customer_code' =>
                    $customerCode,

                'provider_authorization_code' =>
                    $authorizationCode,

                'starts_at' => $now,
                'current_period_start' => $now,
                'current_period_end' => $periodEnd,
            ]);
        } else {
            /*
             * Existing trial/past_due/grace subscription.
             *
             * Convert it into the newly purchased plan.
             */
            $subscription->forceFill([
                'plan_id' => $plan->id,
                'status' => 'active',
                'provider' => 'paystack',

                'provider_customer_code' =>
                    $customerCode,

                'provider_authorization_code' =>
                    $authorizationCode,

                'starts_at' =>
                    $subscription->starts_at ?? $now,

                'current_period_start' => $now,
                'current_period_end' => $periodEnd,

                'grace_period_ends_at' => null,
                'cancelled_at' => null,
                'ended_at' => null,
            ])->save();

            $subscription->refresh();
        }

        /*
         * Establish recurring billing with Paystack.
         */
        $providerSubscription =
            $this->paymentGateway->createSubscription([
                'customer_code' => $customerCode,
                'plan_code' => $plan->paystack_plan_code,
                'authorization_code' => $authorizationCode,
            ]);

        /*
         * Paystack must return a provider subscription code.
         */
        if (
            ! ($providerSubscription['success'] ?? false) ||
            empty($providerSubscription['subscription_code'])
        ) {
            throw ValidationException::withMessages([
                'payment' => 'Unable to create the recurring subscription.',
            ]);
        }

        /*
         * Persist all provider identifiers required for
         * future billing management.
         */
        $subscription->forceFill([
            'provider_customer_code' =>
                $providerSubscription['customer_code']
                ?? $customerCode,

            'provider_authorization_code' =>
                $authorizationCode,

            'provider_subscription_code' =>
                $providerSubscription['subscription_code'],

            'provider_email_token' =>
                $providerSubscription['email_token']
                ?? null,
        ])->save();

        /*
         * Associate the successful payment with its subscription.
         */
        $payment->forceFill([
            'subscription_id' => $subscription->id,
        ])->save();
    }

    /**
     * Convert a major-unit amount into minor units.
     *
     * Example:
     * 10,000.00 NGN -> 1,000,000 kobo.
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
            + (int) str_pad(
                substr($decimal, 0, 2),
                2,
                '0'
            )
        );
    }
}
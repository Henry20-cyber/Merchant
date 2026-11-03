<?php

namespace App\Domains\Payment\Services;

use App\Domains\Payment\Contracts\PaymentGateway;
use App\Domains\Payment\Models\Payment;
use App\Domains\Subscription\Models\Subscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubscriptionPaymentFailureService
{
    public function __construct(private PaymentGateway $paymentGateway)
    {
    }

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

            $plan = $subscription->plan;
            if (! $plan) {
                throw ValidationException::withMessages([
                    'plan' => 'MerchantOS subscription plan could not be found.',
                ]);
            }

            $reference = data_get($data, 'reference');
            if ($reference !== null) {
                if (! is_string($reference) || trim($reference) === '') {
                    throw ValidationException::withMessages([
                        'reference' => 'Paystack transaction reference is invalid.',
                    ]);
                }

                $payment = Payment::query()
                    ->where('reference', $reference)
                    ->lockForUpdate()
                    ->first();

                if ($payment && (
                    (string) $payment->business_id !== (string) $subscription->business_id
                    || (string) $payment->subscription_id !== (string) $subscription->id
                    || $payment->sale_id !== null
                    || $payment->method !== 'paystack'
                )) {
                    throw ValidationException::withMessages([
                        'reference' => 'Paystack transaction reference belongs to a different transaction.',
                    ]);
                }

            }

            // A failure event contradicting a locally recorded successful
            // charge must not move the subscription back to past_due.
            if (isset($payment) && $payment->status === 'paid') {
                return $subscription->refresh();
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
             * Webhook timestamps do not establish transaction ordering.
             * Reconcile against Paystack's current subscription and most
             * recent invoice instead. This lets a current failure move the
             * subscription to past_due while making an old failure delivery
             * harmless after Paystack has recorded a newer successful invoice.
             */
            $providerSubscription = $this->paymentGateway->fetchSubscription($subscriptionCode);
            $providerData = data_get($providerSubscription, 'data');
            if (! is_array($providerData)
                || data_get($providerSubscription, 'success') !== true
                || (string) data_get($providerData, 'subscription_code') !== (string) $subscriptionCode
                || (string) data_get($providerData, 'customer.customer_code') !== (string) $customerCode) {
                throw new \RuntimeException('Paystack subscription identity could not be verified.');
            }

            $providerStatus = data_get($providerData, 'status');

            if (in_array($providerStatus, ['cancelled', 'complete', 'completed'], true)) {
                return $subscription->refresh();
            }

            $invoice = null;
            if (array_key_exists('most_recent_invoice', $providerData)) {
                $invoice = $providerData['most_recent_invoice'];
                if (! is_array($invoice) || ! is_string($invoice['status'] ?? null)) {
                    throw new \RuntimeException('Paystack returned malformed latest invoice data.');
                }
            }

            if (in_array($providerStatus, ['active', 'non-renewing'], true)) {
                $invoiceStatus = $invoice['status'] ?? null;
                if (in_array($invoiceStatus, ['failed', 'attention'], true)) {
                    throw new \RuntimeException('Paystack subscription and latest invoice statuses conflict.');
                }

                return $subscription->refresh();
            }

            if ($providerStatus !== 'attention') {
                throw new \RuntimeException('Paystack did not confirm the current subscription invoice failure.');
            }

            // Subscription checkout supports NGN only. Validate amounts in
            // kobo without comparing them to a local plan price that may have
            // changed since this provider subscription was created.
            $providerCurrency = data_get($providerData, 'plan.currency');
            if (! is_string($providerCurrency) || strtoupper($providerCurrency) !== 'NGN') {
                throw ValidationException::withMessages([
                    'currency' => 'Paystack subscription uses an unsupported or invalid currency.',
                ]);
            }

            $providerAmount = data_get($providerData, 'amount');
            if (! $this->isValidMinorAmount($providerAmount)
                || $this->normalizeMinorAmount($providerAmount) === '0') {
                throw ValidationException::withMessages([
                    'amount' => 'Paystack subscription amount is missing, invalid, or zero.',
                ]);
            }

            $webhookAmount = data_get($data, 'amount');
            if ($webhookAmount !== null && (! $this->isValidMinorAmount($webhookAmount)
                || $this->normalizeMinorAmount($webhookAmount) !== $this->normalizeMinorAmount($providerAmount))) {
                throw ValidationException::withMessages([
                    'amount' => 'Paystack webhook amount does not match the subscription billing amount.',
                ]);
            }

            if ($invoice !== null && array_key_exists('amount', $invoice)) {
                $invoiceAmount = $invoice['amount'];
                if (! $this->isValidMinorAmount($invoiceAmount)
                    || $this->normalizeMinorAmount($invoiceAmount) !== $this->normalizeMinorAmount($providerAmount)) {
                    throw ValidationException::withMessages([
                        'amount' => 'Paystack invoice amount does not match the subscription billing amount.',
                    ]);
                }
            }

            $webhookCurrency = data_get($data, 'currency');
            if ($webhookCurrency !== null && (! is_string($webhookCurrency)
                || strtoupper($webhookCurrency) !== strtoupper($providerCurrency))) {
                throw ValidationException::withMessages([
                    'currency' => 'Failed transaction currency does not match the Paystack subscription currency.',
                ]);
            }

            if ($invoice !== null) {
                if (! in_array($invoice['status'], ['failed', 'attention'], true)) {
                    throw new \RuntimeException('Paystack subscription and latest invoice statuses conflict.');
                }

                if (array_key_exists('currency', $invoice)
                    && (! is_string($invoice['currency'])
                        || strtoupper($invoice['currency']) !== strtoupper($providerCurrency))) {
                    throw ValidationException::withMessages([
                        'currency' => 'Paystack invoice currency does not match the subscription currency.',
                    ]);
                }
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

    private function isValidMinorAmount(mixed $amount): bool
    {
        return (is_int($amount) && $amount >= 0)
            || (is_string($amount) && preg_match('/\A\d+\z/D', $amount) === 1);
    }

    private function normalizeMinorAmount(int|string $amount): string
    {
        return ltrim((string) $amount, '0') ?: '0';
    }
}

<?php

namespace App\Domains\Subscription\Services;

use App\Domains\Organization\Models\Business;
use App\Domains\Payment\Contracts\PaymentGateway;
use App\Domains\Payment\Models\Payment;
use App\Domains\Subscription\Models\Subscription;
use App\Domains\Subscription\Models\SubscriptionPlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SubscriptionCheckoutService
{
    public function __construct(
        private PaymentGateway $paymentGateway,
    ) {
    }

    /**
     * Start checkout for a subscription plan.
     *
     * This method creates a pending payment and initializes
     * the external payment gateway.
     *
     * It does NOT activate the subscription.
     */
    public function checkout(
        Business $business,
        SubscriptionPlan $plan,
        string $email,
    ): array {
        $this->validatePlan($plan);

        $this->validateCurrency($plan);

        $subscription = $business->subscription()->first();

        /*
         * A suspended subscription cannot start a new checkout.
         */
        if ($subscription?->status === 'suspended') {
            throw ValidationException::withMessages([
                'subscription' => 'This subscription is suspended.',
            ]);
        }

        /*
         * A restricted subscription must resolve its outstanding
         * billing problem before another checkout is created.
         */
        if ($subscription?->status === 'restricted') {
            throw ValidationException::withMessages([
                'subscription' => 'This subscription is restricted.',
            ]);
        }

        /*
         * Generate a MerchantOS-owned reference.
         *
         * Paystack receives this exact reference.
         */
        $reference = $this->generateReference();

        /*
         * Store the payment before calling Paystack.
         *
         * This gives MerchantOS a durable record of the
         * payment attempt.
         */
        $payment = DB::transaction(function () use (
            $business,
            $subscription,
            $plan,
            $reference,
        ) {
            return Payment::create([
                'business_id' => $business->id,
                'subscription_id' => $subscription?->id,
                'sale_id' => null,
                'amount' => $plan->price,
                'method' => 'paystack',
                'status' => 'pending',
                'reference' => $reference,
                'metadata' => [
                    'type' => 'subscription',
                    'business_id' => $business->id,
                    'subscription_id' => $subscription?->id,
                    'plan_id' => $plan->id,
                    'plan_slug' => $plan->slug,
                    'billing_interval' => $plan->billing_interval,
                ],
                'paid_at' => null,
            ]);
        });

        try {
            $result = $this->paymentGateway->initialize([
                'email' => $email,

                /*
                 * Paystack expects the amount in kobo.
                 */
                'amount' => $this->toMinorUnits(
                    (string) $plan->price
                ),

                'reference' => $reference,

                'callback_url' => config(
                    'app.frontend_url'
                ) . '/subscription/payment/callback',

                'metadata' => [
                    'payment_id' => $payment->id,
                    'business_id' => $business->id,
                    'subscription_id' => $subscription?->id,
                    'plan_id' => $plan->id,
                    'plan_slug' => $plan->slug,
                    'billing_interval' => $plan->billing_interval,
                ],
            ]);
        } catch (\Throwable $e) {
            /*
             * The payment attempt remains pending/recorded.
             *
             * We deliberately don't mark it paid because no
             * successful payment occurred.
             */
            throw $e;
        }

        if (! ($result['success'] ?? false)) {
            throw new \RuntimeException(
                'Unable to initialize subscription payment.'
            );
        }

        return [
            'payment_id' => $payment->id,
            'reference' => $reference,
            'authorization_url' => $result['authorization_url'],
            'access_code' => $result['access_code'],
        ];
    }

    private function validatePlan(
        SubscriptionPlan $plan
    ): void {
        if (! $plan->is_active) {
            throw ValidationException::withMessages([
                'plan' => 'The selected subscription plan is inactive.',
            ]);
        }
    }

    private function validateCurrency(
        SubscriptionPlan $plan
    ): void {
        if (strtoupper($plan->currency) !== 'NGN') {
            throw ValidationException::withMessages([
                'plan' => 'The selected subscription plan uses an unsupported currency.',
            ]);
        }
    }

    private function generateReference(): string
    {
        return 'MERCHANTOS-' . strtoupper(
            Str::random(20)
        );
    }

    /**
     * Convert naira to kobo without floating-point arithmetic.
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

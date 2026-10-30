<?php

namespace App\Domains\Subscription\Services;

use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Models\BusinessUser;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
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
        User $actor,
        Business $business,
        SubscriptionPlan $plan,
        string $email,
    ): array {
        // Reject unauthorized callers before exposing subscription state.
        $this->authorizeBillingManager($actor, $business);
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

        // This release does not yet implement a safe Paystack recurring-plan
        // replacement workflow. Do not collect a second payment that cannot
        // be applied to an existing recurring subscription.
        if ($subscription?->provider_subscription_code) {
            throw ValidationException::withMessages([
                'subscription' => 'A recurring subscription is already active. Plan changes are temporarily unavailable; contact support before making another payment.',
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
            $actor,
        ) {
            $this->authorizeBillingManager($actor, $business);

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


    /**
     * Ensure the actor is an active member with billing permission
     * in this exact tenant. Route middleware is not a service boundary.
     */
    private function authorizeBillingManager(User $actor, Business $business): void
    {
        $isActiveMember = BusinessUser::query()
            ->where('business_id', $business->id)
            ->where('user_id', $actor->id)
            ->where('status', 'active')
            ->lockForUpdate()
            ->first();

        if (! $isActiveMember) {
            throw new AccessDeniedHttpException(
                'You do not have access to manage billing for this business.'
            );
        }

        setPermissionsTeamId($business->id);
        $actor->unsetRelation('roles');
        $actor->unsetRelation('permissions');

        if (! $actor->can('subscription.manage')) {
            throw new AccessDeniedHttpException(
                'You do not have permission to manage this business subscription.'
            );
        }
    }

    private function validatePlan(
        SubscriptionPlan $plan
    ): void {
        if (! $plan->is_active) {
            throw ValidationException::withMessages([
                'plan' => 'The selected subscription plan is inactive.',
            ]);
        }

        if (preg_match('/^0+(?:\.0+)?$/', (string) $plan->price) !== 1 && ! $plan->paystack_plan_code) {
            throw ValidationException::withMessages([
                'plan' => 'This plan is not yet configured for recurring billing. Please contact support.',
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

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
    ) {}

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

        /*
         * Serialize checkout decisions for this tenant. Locking the business
         * row also protects the "no subscription row exists yet" case.
         * The transaction ends before the external Paystack request.
         */
        [$payment, $subscription] = DB::transaction(function () use (
            $actor,
            $business,
            $plan,
        ) {
            $lockedBusiness = Business::query()
                ->whereKey($business->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->authorizeBillingManager($actor, $lockedBusiness);

            $this->validatePlan($plan);
            $this->validateCurrency($plan);

            $subscription = Subscription::query()
                ->where('business_id', $lockedBusiness->id)
                ->lockForUpdate()
                ->first();

            if ($subscription?->status === 'suspended') {
                throw ValidationException::withMessages([
                    'subscription' => 'This subscription is suspended.',
                ]);
            }

            if ($subscription?->status === 'restricted') {
                throw ValidationException::withMessages([
                    'subscription' => 'This subscription is restricted.',
                ]);
            }

            /*
             * Never replace an active subscription, even if it is a legacy
             * manual subscription without Paystack identifiers.
             */
            if (
                $subscription?->status === 'active' ||
                $subscription?->provider_subscription_code
            ) {
                throw ValidationException::withMessages([
                    'subscription' => 'A subscription is already active or has recurring billing configured. Plan changes are temporarily unavailable; contact support before making another payment.',
                ]);
            }

            /*
             * A pending transaction may still be payable at Paystack even
             * when the customer closes the browser. Do not auto-expire it.
             */
            $pendingCheckoutExists = Payment::query()
                ->where('business_id', $lockedBusiness->id)
                ->where('method', 'paystack')
                ->where('status', 'pending')
                ->where('metadata->type', 'subscription')
                ->lockForUpdate()
                ->exists();

            if ($pendingCheckoutExists) {
                throw ValidationException::withMessages([
                    'subscription' => 'A subscription payment is already pending. Complete it or contact support if the payment was abandoned before starting another checkout.',
                ]);
            }

            $reference = $this->generateReference();

            $payment = Payment::create([
                'business_id' => $lockedBusiness->id,
                'subscription_id' => $subscription?->id,
                'sale_id' => null,
                'amount' => $plan->price,
                'method' => 'paystack',
                'status' => 'pending',
                'reference' => $reference,
                'metadata' => [
                    'type' => 'subscription',
                    'business_id' => $lockedBusiness->id,
                    'subscription_id' => $subscription?->id,
                    'plan_id' => $plan->id,
                    'plan_slug' => $plan->slug,
                    'billing_interval' => $plan->billing_interval,
                ],
                'paid_at' => null,
            ]);

            return [$payment, $subscription];
        });

        try {
            $result = $this->paymentGateway->initialize([
                'email' => $email,
                'amount' => $this->toMinorUnits((string) $plan->price),
                'reference' => $payment->reference,
                'callback_url' => config('app.frontend_url')
                    . '/subscription/payment/callback',
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
             * Retain the pending ledger entry. A timeout does not prove
             * Paystack failed to initialize the transaction.
             */
            throw $e;
        }

        if (
            ! ($result['success'] ?? false) ||
            empty($result['authorization_url']) ||
            empty($result['access_code'])
        ) {
            throw new \RuntimeException(
                'Unable to initialize subscription payment.'
            );
        }

        return [
            'payment_id' => $payment->id,
            'reference' => $payment->reference,
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

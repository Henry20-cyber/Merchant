<?php

namespace App\Domains\Subscription\Services;

use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Models\BusinessUser;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use App\Domains\Payment\Contracts\PaymentGateway;
use App\Domains\Payment\Models\Payment;
use App\Domains\Payment\Models\SubscriptionCheckoutRecovery;
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
        ?string $recoveryId = null,
    ): array {

        $verifiedRetryPayment = $this->verifyRetryableHistoryBeforeNewAttempt($actor, $business, $plan, $recoveryId);
        if ($verifiedRetryPayment) {
            return $this->confirmedPaymentResponse($verifiedRetryPayment);
        }

        /*
         * Serialize checkout decisions for this tenant. Locking the business
         * row also protects the "no subscription row exists yet" case.
         * The transaction ends before the external Paystack request.
         */
        [$payment, $subscription, $recovering] = DB::transaction(function () use (
            $actor,
            $business,
            $plan,
            $recoveryId,
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

            if (SubscriptionCheckoutRecovery::query()
                ->where('business_id', $lockedBusiness->id)
                ->where('status', 'manual_review')
                ->exists()) {
                throw ValidationException::withMessages([
                    'subscription' => 'A previous subscription payment needs support review before another checkout can start.',
                ]);
            }

            // An active subscription is replaceable through ordinary checkout
            // only when its current plan is explicitly the free plan. Treat a
            // missing/legacy plan as paid so inconsistent records stay guarded.
            $currentPlanIsFree = $subscription !== null
                && $subscription->status === 'active'
                && $subscription->plan !== null
                && $subscription->plan->slug === 'free'
                && preg_match('/^0+(?:\.0+)?$/', (string) $subscription->plan->price) === 1;

            if (
                ($subscription?->status === 'active' && ! $currentPlanIsFree) ||
                filled($subscription?->provider_subscription_code)
            ) {
                throw ValidationException::withMessages([
                    'subscription' => 'A subscription is already active or has recurring billing configured. Plan changes are temporarily unavailable; contact support before making another payment.',
                ]);
            }

            /*
             * A pending transaction may still be payable at Paystack even
             * when the customer closes the browser. Do not auto-expire it.
             */
            $pendingPayment = Payment::query()
                ->where('business_id', $lockedBusiness->id)
                ->where('method', 'paystack')
                ->where('status', 'pending')
                ->where('metadata->type', 'subscription')
                ->lockForUpdate()
                ->latest('created_at')
                ->first();

            if ($pendingPayment) {
                if ((string) ($pendingPayment->metadata['plan_id'] ?? '') !== (string) $plan->id) {
                    throw ValidationException::withMessages([
                        'subscription' => 'A different subscription checkout is still pending. Reconcile it before selecting another plan.',
                    ]);
                }
                if ($recoveryId !== null
                    && (string) $pendingPayment->checkout_recovery_id !== $recoveryId) {
                    throw ValidationException::withMessages([
                        'recovery_id' => 'The pending checkout does not match the requested recovery.',
                    ]);
                }
                if ($pendingPayment->checkoutRecovery?->status === 'manual_review') {
                    throw ValidationException::withMessages([
                        'subscription' => 'This checkout is under payment review. Contact support before retrying.',
                    ]);
                }
                if ($pendingPayment->checkoutRecovery?->winning_payment_id !== null
                    && (string) $pendingPayment->checkoutRecovery->winning_payment_id !== (string) $pendingPayment->id) {
                    throw ValidationException::withMessages([
                        'subscription' => 'Another checkout attempt has already succeeded. This pending attempt cannot be resumed; contact support if you see another charge.',
                    ]);
                }
                if (! $pendingPayment->checkout_recovery_id) {
                    $recovery = $this->createRecovery($lockedBusiness, $subscription, $plan, $pendingPayment);
                    $pendingPayment->forceFill([
                        'checkout_recovery_id' => $recovery->id,
                        'attempt_number' => 1,
                    ])->save();
                }
                return [$pendingPayment->fresh(), $subscription, true];
            }

            // Continue the latest retryable purchase for this exact plan.
            // The group row is locked so concurrent retries cannot allocate
            // the same attempt number.
            $recoveryQuery = SubscriptionCheckoutRecovery::query()
                ->where('business_id', $lockedBusiness->id)
                ->where('plan_id', $plan->id)
                ->where('status', 'retryable')
                ->whereNull('winning_payment_id');
            if ($recoveryId !== null) {
                $recoveryQuery->whereKey($recoveryId);
            } else {
                // Compatibility for existing clients. New clients should send
                // the recovery_id returned by checkout/history responses.
                $recoveryQuery->where('created_at', '>=', now()->subDays(30));
            }
            $recovery = $recoveryQuery->latest('created_at')->lockForUpdate()->first();
            if ($recoveryId !== null && ! $recovery) {
                throw ValidationException::withMessages([
                    'recovery_id' => 'The requested checkout recovery is unavailable for this business and plan.',
                ]);
            }
            if ($recoveryId !== null && $recovery && ! $this->recoveryTermsMatchPlan($recovery, $plan, $subscription)) {
                throw ValidationException::withMessages([
                    'recovery_id' => 'The selected plan terms have changed since this recovery began. Start a new checkout to accept the current terms.',
                ]);
            }

            if ($recovery && $this->recoveryTermsMatchPlan($recovery, $plan, $subscription)) {
                $attemptNumber = ((int) $recovery->payments()->max('attempt_number')) + 1;
                $recovery->forceFill(['status' => 'pending'])->save();
            } else {
                $recovery = $this->createRecovery($lockedBusiness, $subscription, $plan);
                $attemptNumber = 1;
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
                'checkout_recovery_id' => $recovery->id,
                'attempt_number' => $attemptNumber,
                'metadata' => [
                    'type' => 'subscription',
                    'business_id' => $lockedBusiness->id,
                    'subscription_id' => $subscription?->id,
                    'plan_id' => $plan->id,
                    'plan_slug' => $plan->slug,
                    'billing_interval' => $plan->billing_interval,
                    'currency' => strtoupper($plan->currency),
                    'paystack_plan_code' => $plan->paystack_plan_code,
                    'checkout_recovery_id' => $recovery->id,
                    'attempt_number' => $attemptNumber,
                ],
                'paid_at' => null,
            ]);

            return [$payment, $subscription, false];
        });

        if ($recovering) {
            // The confirmation service owns verification and state transitions;
            // consume its result so this reference is verified only once.
            $reconciled = app(\App\Domains\Payment\Services\PaymentConfirmationService::class)->confirm($payment->reference);
            if ($reconciled->status === 'failed') {
                $recovery = $reconciled->checkoutRecovery;
                $retryAvailable = $recovery?->status === 'retryable';
                $outcome = $retryAvailable
                    ? ($recovery->retry_reason ?? 'failed')
                    : ($recovery?->status === 'manual_review' ? 'manual_review' : 'reconciling');
                return [
                    'payment_id' => $reconciled->id,
                    'reference' => $reconciled->reference,
                    'status' => 'failed',
                    'retry_available' => $retryAvailable,
                    'outcome' => $outcome,
                    'session_expired' => $retryAvailable && $recovery?->retry_reason === 'session_expired',
                    'manual_review' => $outcome === 'manual_review',
                    'recovery_id' => $reconciled->checkout_recovery_id,
                    'attempt_number' => $reconciled->attempt_number,
                ];
            }
            if ($reconciled->status === 'paid') {
                return $this->confirmedPaymentResponse($reconciled);
            }

            $checkout = $payment->metadata['checkout'] ?? [];
            return [
                'payment_id' => $payment->id,
                'reference' => $payment->reference,
                'recovery_id' => $payment->checkout_recovery_id,
                'attempt_number' => $payment->attempt_number,
                'authorization_url' => $checkout['authorization_url'] ?? null,
                'access_code' => $checkout['access_code'] ?? null,
                'status' => 'pending',
                'outcome' => empty($checkout['authorization_url']) ? 'reconciling' : 'pending',
                'resumed' => ! empty($checkout['authorization_url']),
                'reconciling' => empty($checkout['authorization_url']),
                'session_state' => empty($checkout['authorization_url']) ? 'unavailable' : 'stored_unverified',
                // A stored URL may have outlived Paystack's session timeout.
                // Verification can establish transaction status, not URL usability.
                'resume_possible' => empty($checkout['authorization_url']) ? false : null,
            ];
        }

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

        if (($result['reference'] ?? null) !== $payment->reference) {
            throw new \RuntimeException('Paystack initialization returned a mismatched transaction reference.');
        }

        $payment->forceFill([
            'metadata' => array_merge($payment->metadata ?? [], [
                'provider_reference' => $result['reference'] ?? $payment->reference,
                'checkout' => [
                    'authorization_url' => $result['authorization_url'],
                    'access_code' => $result['access_code'],
                ],
            ]),
        ])->save();

        return [
            'payment_id' => $payment->id,
            'reference' => $payment->reference,
            'recovery_id' => $payment->checkout_recovery_id,
            'attempt_number' => $payment->attempt_number,
            'authorization_url' => $result['authorization_url'],
            'access_code' => $result['access_code'],
            'status' => 'pending',
            'outcome' => 'pending',
            'resumed' => false,
            'session_state' => 'stored_unverified',
            'resume_possible' => true,
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

    private function createRecovery(
        Business $business,
        ?Subscription $subscription,
        SubscriptionPlan $plan,
        ?Payment $existingAttempt = null,
    ): SubscriptionCheckoutRecovery
    {
        $metadata = $existingAttempt?->metadata ?? [];
        $terms = $existingAttempt ? [
            // Preserve only facts captured on the original local payment.
            // Missing legacy facts remain missing and require review after capture.
            'plan_id' => $metadata['plan_id'] ?? null,
            'plan_slug' => $metadata['plan_slug'] ?? null,
            'amount' => (string) $existingAttempt->amount,
            'currency' => isset($metadata['currency']) ? strtoupper((string) $metadata['currency']) : null,
            'billing_interval' => $metadata['billing_interval'] ?? null,
            'paystack_plan_code' => $metadata['paystack_plan_code'] ?? null,
            'subscription_id' => $metadata['subscription_id'] ?? $existingAttempt->subscription_id,
        ] : [
            'plan_id' => $plan->id,
            'plan_slug' => $plan->slug,
            'plan_name' => $plan->name,
            'amount' => (string) $plan->price,
            'currency' => strtoupper($plan->currency),
            'billing_interval' => $plan->billing_interval,
            'paystack_plan_code' => $plan->paystack_plan_code,
            'subscription_id' => $subscription?->id,
        ];

        return SubscriptionCheckoutRecovery::query()->create([
            'business_id' => $business->id,
            'subscription_id' => $subscription?->id,
            'plan_id' => $plan->id,
            'status' => 'pending',
            'purchase_terms' => $terms,
        ]);
    }

    private function recoveryTermsMatchPlan(
        SubscriptionCheckoutRecovery $recovery,
        SubscriptionPlan $plan,
        ?Subscription $subscription,
    ): bool {
        $terms = $recovery->purchase_terms ?? [];

        return (string) $recovery->plan_id === (string) $plan->id
            && (string) ($terms['plan_id'] ?? '') === (string) $plan->id
            && (string) ($terms['amount'] ?? '') === (string) $plan->price
            && strtoupper((string) ($terms['currency'] ?? '')) === strtoupper((string) $plan->currency)
            && (string) ($terms['billing_interval'] ?? '') === (string) $plan->billing_interval
            && (string) ($terms['paystack_plan_code'] ?? '') === (string) $plan->paystack_plan_code
            && (string) ($terms['subscription_id'] ?? '') === (string) ($subscription?->id ?? '');
    }

    private function verifyRetryableHistoryBeforeNewAttempt(
        User $actor,
        Business $business,
        SubscriptionPlan $plan,
        ?string $recoveryId,
    ): ?Payment {
        $query = SubscriptionCheckoutRecovery::query()
            ->where('business_id', $business->id)
            ->where('plan_id', $plan->id)
            ->where('status', 'retryable')
            ->whereNull('winning_payment_id');
        if ($recoveryId !== null) {
            $query->whereKey($recoveryId);
        } else {
            $query->where('created_at', '>=', now()->subDays(30));
        }

        $recovery = $query->with('payments')->latest('created_at')->first();
        if (! $recovery) return null;

        $unverifiedFailures = $recovery->payments
            ->where('status', 'failed')
            ->filter(fn (Payment $payment): bool => ! in_array($payment->last_provider_status, ['failed', 'abandoned'], true));
        if ($unverifiedFailures->isEmpty()) return null;

        // A retry can create another charge, so first verify every legacy
        // failed attempt that has no retained definitive provider status.
        // Authorization is repeated inside the locked checkout transaction.
        $lockedBusiness = Business::query()->whereKey($business->id)->firstOrFail();
        $this->authorizeBillingManager($actor, $lockedBusiness);
        $confirmation = app(\App\Domains\Payment\Services\PaymentConfirmationService::class);
        foreach ($unverifiedFailures as $payment) {
            $checked = $confirmation->confirm((string) $payment->reference);
            if ($checked->status === 'paid') {
                return $checked;
            }
            if ($checked->status !== 'failed'
                || ! in_array($checked->last_provider_status, ['failed', 'abandoned'], true)) {
                throw ValidationException::withMessages([
                    'subscription' => 'A previous payment outcome is not definitively failed. Reconciliation is required before retrying.',
                ]);
            }
        }

        return null;
    }

    private function confirmedPaymentResponse(Payment $payment): array
    {
        $requiresReview = ! empty($payment->metadata['entitlement_review'])
            || $payment->checkoutRecovery?->status === 'manual_review';

        return [
            'payment_id' => $payment->id,
            'reference' => $payment->reference,
            'status' => 'paid',
            'outcome' => $requiresReview ? 'manual_review' : 'succeeded',
            'manual_review' => $requiresReview,
            'recovery_id' => $payment->checkout_recovery_id,
            'attempt_number' => $payment->attempt_number,
        ];
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

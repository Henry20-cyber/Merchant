<?php

namespace App\Domains\Payment\Services;

use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Services\BusinessVatService;
use App\Domains\Payment\Contracts\PaymentGateway;
use App\Domains\Payment\Models\Payment;
use App\Domains\Payment\Models\SubscriptionCheckoutRecovery;
use App\Domains\Payment\Models\SubscriptionPaymentRecoveryAudit;
use App\Domains\Subscription\Models\Subscription;
use App\Domains\Subscription\Models\SubscriptionPlan;
use App\Domains\Subscription\Services\SubscriptionPeriodCalculator;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * @phpstan-type RecoveryTerms array{payment_id:string,business_id:string,subscription_id:string,plan_id:string,plan_slug:string,paystack_plan_code:string,amount_kobo:int,currency:string,billing_interval:string}
 */
class SubscriptionPaymentRecoveryService
{
    public function __construct(
        private PaymentGateway $gateway,
        private BusinessVatService $vatService,
        private SubscriptionPeriodCalculator $periodCalculator,
    ) {}

    /**
     * Reconcile a paid historical transaction using terms explicitly supplied
     * by an authorized operator and independently verified against Paystack.
     * Dry-run performs all reads/preflight checks but writes nothing.
     *
     * @param  RecoveryTerms  $terms
     * @return array<string, mixed>
     */
    public function recover(string $reference, array $terms, User $actor, bool $execute = false): array
    {
        try {
            return $this->performRecovery($reference, $terms, $actor, $execute);
        } catch (Throwable $exception) {
            if ($execute) {
                try {
                    $payment = Payment::query()
                        ->where('reference', $reference)
                        ->whereKey($terms['payment_id'])
                        ->where('business_id', $terms['business_id'])
                        ->first();
                    if ($payment && ! SubscriptionPaymentRecoveryAudit::query()
                        ->where('payment_id', $payment->id)
                        ->where('event_type', 'subscription_entitlement_recovered')
                        ->exists()) {
                        SubscriptionPaymentRecoveryAudit::query()->create([
                            'business_id' => $payment->business_id,
                            'payment_id' => $payment->id,
                            'actor_id' => $actor->id,
                            'event_type' => 'subscription_entitlement_recovery_blocked',
                            'event_payload' => [
                                'reference' => $reference,
                                'decision' => 'blocked',
                                'reason_code' => 'preflight_or_state_guard_failed',
                                'business_id' => $payment->business_id,
                                'subscription_id' => $terms['subscription_id'],
                                'plan_id' => $terms['plan_id'],
                                'exception_class' => class_basename($exception),
                                'payment_status_unchanged' => true,
                                'entitlement_unchanged' => true,
                            ],
                            'created_at' => now(),
                        ]);
                    }
                } catch (Throwable) {
                    // Preserve the original safe preflight failure if the audit store is unavailable.
                }
            }

            throw $exception;
        }
    }

    /** @param RecoveryTerms $terms
     * @return array<string, mixed>
     */
    private function performRecovery(string $reference, array $terms, User $actor, bool $execute): array
    {
        $local = $this->loadAndValidateLocalState($reference, $terms);
        /** @var Payment $payment */
        $payment = $local['payment'];
        /** @var Business $business */
        $business = $local['business'];
        /** @var Subscription $subscription */
        $subscription = $local['subscription'];
        /** @var SubscriptionPlan $plan */
        $plan = $local['plan'];
        /** @var SubscriptionPlan $subscriptionPlan */
        $subscriptionPlan = $local['subscription_plan'];

        $priorAudit = SubscriptionPaymentRecoveryAudit::query()
            ->where('payment_id', $payment->id)
            ->where('event_type', 'subscription_entitlement_recovered')
            ->latest('created_at')
            ->first();
        if ($priorAudit) {
            if ($subscription->plan_id === $plan->id
                && $subscription->status === 'active'
                && $subscription->current_period_start !== null
                && Carbon::parse($subscription->current_period_start)->equalTo(Carbon::parse($payment->paid_at))) {
                return ['eligible' => true, 'executed' => false, 'already_recovered' => true, 'payment_id' => $payment->id, 'subscription_id' => $subscription->id];
            }
            throw new RuntimeException('A prior recovery audit exists but the entitlement no longer matches it; manual review is required.');
        }
        if ($subscription->status !== 'active'
            || $subscriptionPlan->slug !== 'free'
            || preg_match('/^0+(?:\.0+)?$/', (string) $subscriptionPlan->price) !== 1) {
            throw new RuntimeException('The subscription is not currently an active Free subscription.');
        }

        $verified = $this->gateway->verify($reference);
        $this->assertProviderTransaction($verified, $reference, $terms);
        $providerPlan = $this->gateway->fetchPlan($terms['paystack_plan_code']);
        $providerPlanData = $providerPlan['data'];
        $this->assertProviderPlan($providerPlan, $providerPlanData, $terms);
        $this->assertNoExistingProviderSubscription($verified, $providerPlanData, $terms);

        if (! $execute) {
            return [
                'eligible' => true,
                'executed' => false,
                'already_recovered' => false,
                'payment_id' => $payment->id,
                'business_id' => $business->id,
                'subscription_id' => $subscription->id,
                'plan' => ['id' => $plan->id, 'slug' => $plan->slug, 'price' => $plan->price, 'currency' => $plan->currency],
                'verified' => ['reference' => $verified['reference'], 'status' => $verified['status'], 'amount' => $verified['amount'], 'currency' => $verified['currency']],
                'recurring_billing' => 'not provisioned: explicit recurring consent is not recorded',
            ];
        }

        return DB::transaction(function () use ($reference, $terms, $actor, $verified, $providerPlanData, $payment, $business, $subscription, $plan): array {
            $lockedBusiness = Business::query()->whereKey($business->id)->lockForUpdate()->firstOrFail();
            $lockedPayment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $lockedSubscription = Subscription::query()->whereKey($subscription->id)->lockForUpdate()->firstOrFail();
            $lockedSubscriptionPlan = SubscriptionPlan::query()->whereKey($lockedSubscription->plan_id)->lockForUpdate()->firstOrFail();

            if ($lockedPayment->reference !== $reference
                || $lockedPayment->status !== 'paid'
                || ! Carbon::parse($lockedPayment->paid_at)->equalTo(Carbon::parse($payment->paid_at))
                || (string) $lockedPayment->business_id !== (string) $lockedBusiness->id
                || (string) $lockedPayment->subscription_id !== (string) $lockedSubscription->id
                || (string) $lockedSubscription->business_id !== (string) $lockedBusiness->id
                || (string) $lockedSubscription->plan_id !== (string) $subscription->plan_id) {
                throw new RuntimeException('Payment or subscription state changed during recovery preflight.');
            }

            $otherWinner = SubscriptionCheckoutRecovery::query()->where('winning_payment_id', $lockedPayment->id);
            if ($lockedPayment->checkout_recovery_id !== null) {
                $otherWinner->where('id', '<>', $lockedPayment->checkout_recovery_id);
            }
            if ($otherWinner->exists()) {
                throw new RuntimeException('Another checkout recovery already claims this payment as its winner.');
            }

            if ($this->hasConflictingPaidPayment(
                $lockedBusiness->id,
                $lockedSubscription->id,
                $lockedPayment->checkout_recovery_id,
                $lockedPayment->id,
                true,
            )) {
                throw new RuntimeException('A conflicting paid subscription payment appeared during recovery.');
            }

            $recovery = $lockedPayment->checkout_recovery_id
                ? SubscriptionCheckoutRecovery::query()->whereKey($lockedPayment->checkout_recovery_id)->lockForUpdate()->first()
                : null;
            if ($lockedPayment->checkout_recovery_id && ! $recovery) {
                throw new RuntimeException('The payment checkout recovery link is invalid.');
            }
            if (! $recovery) {
                $recovery = SubscriptionCheckoutRecovery::query()->create([
                    'business_id' => $lockedBusiness->id,
                    'subscription_id' => $lockedSubscription->id,
                    'plan_id' => $plan->id,
                    'purchase_terms' => $this->purchaseTerms($terms, $lockedSubscription->id, $plan->name),
                    'status' => 'manual_review',
                    'manual_review_at' => now(),
                    'manual_review_reason' => 'Historical paid payment requires explicitly verified entitlement recovery.',
                ]);
            }
            if ((string) $recovery->business_id !== (string) $lockedBusiness->id
                || (string) $recovery->plan_id !== (string) $plan->id
                || ($recovery->subscription_id !== null && (string) $recovery->subscription_id !== (string) $lockedSubscription->id)
                || ($recovery->winning_payment_id !== null && (string) $recovery->winning_payment_id !== (string) $lockedPayment->id)
                || $recovery->payments()->where('status', 'paid')->where('id', '<>', $lockedPayment->id)->exists()) {
                throw new RuntimeException('The recovery group conflicts with this paid transaction.');
            }
            $recoveryTerms = $this->decodeRecord($recovery->purchase_terms);
            foreach (['plan_id' => $plan->id, 'plan_slug' => $terms['plan_slug'], 'amount' => number_format($terms['amount_kobo'] / 100, 2, '.', ''), 'currency' => strtoupper($terms['currency']), 'billing_interval' => $terms['billing_interval'], 'paystack_plan_code' => $terms['paystack_plan_code'], 'subscription_id' => $lockedSubscription->id] as $key => $expected) {
                if (filled($recoveryTerms[$key] ?? null) && (string) $recoveryTerms[$key] !== (string) $expected) {
                    throw new RuntimeException("Existing recovery purchase terms conflict with the verified {$key}.");
                }
            }
            if ($lockedSubscription->status !== 'active'
                || $lockedSubscriptionPlan->slug !== 'free'
                || preg_match('/^0+(?:\.0+)?$/', (string) $lockedSubscriptionPlan->price) !== 1
                || filled($lockedSubscription->provider_subscription_code)
                || filled($lockedSubscription->provider_customer_code)
                || filled($lockedSubscription->provider_authorization_code)) {
                throw new RuntimeException('The subscription no longer matches the Free-to-Starter recovery preconditions.');
            }

            $periodStart = Carbon::parse($lockedPayment->paid_at);
            $before = [
                'plan_id' => $lockedSubscription->plan_id,
                'status' => $lockedSubscription->status,
                'current_period_start' => $this->dateToIso($lockedSubscription->current_period_start),
                'current_period_end' => $this->dateToIso($lockedSubscription->current_period_end),
                'auto_renew' => (bool) $lockedSubscription->auto_renew,
            ];
            $lockedSubscription->forceFill([
                'plan_id' => $plan->id,
                'status' => 'active',
                'provider' => 'paystack',
                'provider_customer_code' => $verified['customer_code'],
                'provider_customer_id' => $verified['customer_id'],
                'provider_authorization_code' => null,
                'provider_subscription_code' => null,
                'provider_email_token' => null,
                'provisioning_status' => 'not_required',
                'provisioning_attempted_at' => null,
                'provisioning_error' => 'Recurring billing consent was not recorded; recurring provisioning was intentionally skipped.',
                'auto_renew' => false,
                'starts_at' => $lockedSubscription->starts_at ?? $periodStart,
                'current_period_start' => $periodStart,
                'current_period_end' => $this->periodCalculator->endFor($periodStart, $terms['billing_interval']),
                'grace_period_ends_at' => null,
                'cancelled_at' => null,
                'ended_at' => null,
            ])->save();

            $paymentMetadata = $this->decodeRecord($lockedPayment->metadata);
            unset($paymentMetadata['entitlement_review']);
            $paymentMetadata = array_merge($paymentMetadata, [
                'currency' => $terms['currency'],
                'paystack_plan_code' => $terms['paystack_plan_code'],
                'checkout_recovery_id' => $recovery->id,
                'attempt_number' => $lockedPayment->attempt_number ?? 1,
                'entitlement_recovery' => [
                    'source' => 'operator_supplied_terms_and_live_provider_verification',
                    'actor_id' => $actor->id,
                    'verified_at' => now()->toISOString(),
                    'recurring_consent_recorded' => false,
                ],
            ]);
            $lockedPayment->forceFill([
                'subscription_id' => $lockedSubscription->id,
                'checkout_recovery_id' => $recovery->id,
                'attempt_number' => $lockedPayment->attempt_number ?? 1,
                'metadata' => $paymentMetadata,
            ])->save();
            $recovery->forceFill([
                'subscription_id' => $lockedSubscription->id,
                'purchase_terms' => $this->purchaseTerms($terms, $lockedSubscription->id, $plan->name),
                'status' => 'succeeded',
                'winning_payment_id' => $lockedPayment->id,
                'last_provider_status' => 'success',
                'manual_review_resolution' => 'Entitlement recovered from the original paid transaction after reference, amount, currency, business, subscription, plan, and Paystack plan were independently validated. Auto-renew remains disabled because explicit recurring consent is not recorded.',
                'manual_review_resolved_at' => now(),
                'manual_review_resolved_by' => $actor->id,
            ])->save();
            $this->vatService->applyDefault($lockedBusiness, $plan);

            $after = [
                'plan_id' => $lockedSubscription->plan_id,
                'status' => $lockedSubscription->status,
                'current_period_start' => $this->dateToIso($lockedSubscription->current_period_start),
                'current_period_end' => $this->dateToIso($lockedSubscription->current_period_end),
                'auto_renew' => (bool) $lockedSubscription->auto_renew,
                'provisioning_status' => $lockedSubscription->provisioning_status,
            ];
            SubscriptionPaymentRecoveryAudit::query()->create([
                'business_id' => $lockedBusiness->id,
                'payment_id' => $lockedPayment->id,
                'actor_id' => $actor->id,
                'event_type' => 'subscription_entitlement_recovered',
                'event_payload' => [
                    'reference' => $reference,
                    'resolution' => 'verified_paid_entitlement_recovered',
                    'verification' => ['status' => $verified['status'], 'amount' => $verified['amount'], 'currency' => $verified['currency'], 'reference_matched' => true],
                    'provider_plan' => ['plan_code' => $providerPlanData['plan_code'], 'amount' => $providerPlanData['amount'], 'currency' => $providerPlanData['currency'], 'interval' => $providerPlanData['interval']],
                    'business_id' => $lockedBusiness->id,
                    'subscription_id' => $lockedSubscription->id,
                    'plan_id' => $plan->id,
                    'recovery_id' => $recovery->id,
                    'recurring_consent_recorded' => false,
                    'recurring_provisioning' => 'skipped_no_consent',
                    'before' => $before,
                    'after' => $after,
                ],
                'created_at' => now(),
            ]);

            return ['eligible' => true, 'executed' => true, 'already_recovered' => false, 'payment_id' => $lockedPayment->id, 'recovery_id' => $recovery->id, 'subscription_id' => $lockedSubscription->id, 'before' => $before, 'after' => $after];
        });
    }

    /**
     * @param  RecoveryTerms  $terms
     * @return array{payment:Payment,business:Business,subscription:Subscription,subscription_plan:SubscriptionPlan,plan:SubscriptionPlan}
     */
    private function loadAndValidateLocalState(string $reference, array $terms): array
    {
        $payment = Payment::query()->where('reference', $reference)->firstOrFail();
        if ((string) $payment->id !== $terms['payment_id']
            || $payment->status !== 'paid'
            || $payment->method !== 'paystack'
            || $payment->sale_id !== null
            || $payment->paid_at === null
            || (filled($payment->last_provider_status) && $payment->last_provider_status !== 'success')
            || $this->toMinorUnits((string) $payment->amount) !== $terms['amount_kobo']) {
            throw new RuntimeException('The local payment identity, state, or amount does not match supplied recovery evidence.');
        }
        $metadata = $this->decodeRecord($payment->metadata);
        foreach (['type' => 'subscription', 'business_id' => $terms['business_id'], 'subscription_id' => $terms['subscription_id'], 'plan_id' => $terms['plan_id'], 'plan_slug' => $terms['plan_slug'], 'billing_interval' => $terms['billing_interval']] as $key => $expected) {
            if ((string) ($metadata[$key] ?? '') !== (string) $expected) {
                throw new RuntimeException("Captured payment metadata does not prove the supplied {$key}.");
            }
        }
        if (filled($metadata['currency'] ?? null) && strtoupper((string) $metadata['currency']) !== strtoupper($terms['currency'])) {
            throw new RuntimeException('Captured currency conflicts with supplied recovery evidence.');
        }
        if (filled($metadata['paystack_plan_code'] ?? null) && (string) $metadata['paystack_plan_code'] !== $terms['paystack_plan_code']) {
            throw new RuntimeException('Captured Paystack plan code conflicts with supplied recovery evidence.');
        }

        $business = Business::query()->whereKey($terms['business_id'])->firstOrFail();
        $subscription = Subscription::query()->whereKey($terms['subscription_id'])->firstOrFail();
        $subscriptionPlan = SubscriptionPlan::query()->whereKey($subscription->plan_id)->firstOrFail();
        $plan = SubscriptionPlan::query()->whereKey($terms['plan_id'])->firstOrFail();
        if ((string) $payment->business_id !== (string) $business->id
            || (string) $payment->subscription_id !== (string) $subscription->id
            || (string) $subscription->business_id !== (string) $business->id
            || (string) $plan->slug !== $terms['plan_slug']
            || (string) $plan->price !== number_format($terms['amount_kobo'] / 100, 2, '.', '')
            || strtoupper((string) $plan->currency) !== strtoupper($terms['currency'])
            || (string) $plan->billing_interval !== $terms['billing_interval']
            || (string) $plan->paystack_plan_code !== $terms['paystack_plan_code']) {
            throw new RuntimeException('Current business, subscription, or Starter plan configuration conflicts with supplied recovery evidence.');
        }
        if ($this->hasConflictingPaidPayment(
            $business->id,
            $subscription->id,
            $payment->checkout_recovery_id,
            $payment->id,
        )) {
            throw new RuntimeException('Another successful payment is already associated with this subscription.');
        }
        if (! $plan->is_active) {
            throw new RuntimeException('The explicitly supplied subscription plan is no longer active.');
        }
        $otherWinner = SubscriptionCheckoutRecovery::query()->where('winning_payment_id', $payment->id);
        if ($payment->checkout_recovery_id !== null) {
            $otherWinner->where('id', '<>', $payment->checkout_recovery_id);
        }
        if ($otherWinner->exists()) {
            throw new RuntimeException('Another checkout recovery already claims this payment as its winner.');
        }

        return ['payment' => $payment, 'business' => $business, 'subscription' => $subscription, 'subscription_plan' => $subscriptionPlan, 'plan' => $plan];
    }

    private function hasConflictingPaidPayment(
        string $businessId,
        string $subscriptionId,
        ?string $checkoutRecoveryId,
        string $exceptPaymentId,
        bool $lockForUpdate = false,
    ): bool {
        $query = Payment::query()
            ->where('business_id', $businessId)
            ->where('status', 'paid')
            ->where('id', '<>', $exceptPaymentId)
            ->where(function ($query) use ($subscriptionId, $checkoutRecoveryId): void {
                $query->where('subscription_id', $subscriptionId)
                    ->orWhere('metadata->subscription_id', $subscriptionId);

                if ($checkoutRecoveryId !== null) {
                    $query->orWhere('checkout_recovery_id', $checkoutRecoveryId);
                }
            });

        if ($lockForUpdate) {
            return $query->lockForUpdate()->first(['id']) !== null;
        }

        return $query->exists();
    }

    /**
     * @param  array<string, mixed>  $verified
     * @param  RecoveryTerms  $terms
     */
    private function assertProviderTransaction(array $verified, string $reference, array $terms): void
    {
        if (! ($verified['success'] ?? false)
            || ($verified['reference'] ?? null) !== $reference
            || ($verified['status'] ?? null) !== 'success'
            || (int) ($verified['amount'] ?? -1) !== $terms['amount_kobo']
            || strtoupper((string) ($verified['currency'] ?? '')) !== strtoupper($terms['currency'])
            || ! is_string($verified['customer_code'] ?? null)
            || preg_match('/^CUS_[A-Za-z0-9_-]+$/', $verified['customer_code']) !== 1
            || filter_var($verified['customer_id'] ?? null, FILTER_VALIDATE_INT) === false
            || (int) $verified['customer_id'] < 1) {
            throw new RuntimeException('Paystack transaction verification did not exactly match the expected successful payment and customer.');
        }
        $auth = $verified['authorization_code'] ?? null;
        if ($auth !== null && (! is_string($auth) || preg_match('/^AUTH_[A-Za-z0-9_-]+$/', $auth) !== 1)) {
            throw new RuntimeException('Paystack returned malformed authorization information.');
        }
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  RecoveryTerms  $terms
     */
    private function assertProviderPlan(array $result, mixed $data, array $terms): void
    {
        $expectedInterval = $terms['billing_interval'] === 'yearly' ? 'annually' : $terms['billing_interval'];
        if (! ($result['success'] ?? false)
            || ! is_array($data)
            || ($data['plan_code'] ?? null) !== $terms['paystack_plan_code']
            || (int) ($data['amount'] ?? -1) !== $terms['amount_kobo']
            || strtoupper((string) ($data['currency'] ?? '')) !== strtoupper($terms['currency'])
            || ($data['interval'] ?? null) !== $expectedInterval
            || filter_var($data['id'] ?? null, FILTER_VALIDATE_INT) === false
            || (int) $data['id'] < 1) {
            throw new RuntimeException('Paystack plan identity or purchase terms could not be verified.');
        }
    }

    /**
     * @param  array<string, mixed>  $verified
     * @param  array<string, mixed>  $plan
     * @param  RecoveryTerms  $terms
     */
    private function assertNoExistingProviderSubscription(array $verified, array $plan, array $terms): void
    {
        $page = 1;
        do {
            $result = $this->gateway->listSubscriptions(['perPage' => 100, 'page' => $page, 'customer' => (int) $verified['customer_id'], 'plan' => (int) $plan['id']]);
            $data = $result['data'];
            $meta = $result['meta'];
            if (! $result['success'] || ! isset($meta['pageCount']) || filter_var($meta['pageCount'], FILTER_VALIDATE_INT) === false) {
                throw new RuntimeException('Provider subscription lookup was inconclusive; recovery is blocked.');
            }
            $pageCount = (int) $meta['pageCount'];
            if ($pageCount > 10 || $pageCount < 0) {
                throw new RuntimeException('Provider subscription lookup exceeded its safe result bound.');
            }
            if ($pageCount === 0 && $data !== []) {
                throw new RuntimeException('Provider subscription lookup returned records with a zero page count.');
            }
            foreach ($data as $providerSubscription) {
                if (! is_array($providerSubscription)) {
                    throw new RuntimeException('Provider subscription lookup returned malformed data.');
                }
                if (data_get($providerSubscription, 'customer.customer_code') === $verified['customer_code']
                    && data_get($providerSubscription, 'plan.plan_code') === $terms['paystack_plan_code']) {
                    throw new RuntimeException('A matching Paystack subscription already exists; automatic recovery is blocked for manual reconciliation.');
                }
            }
            $page++;
        } while ($page <= $pageCount);
    }

    /**
     * @param  RecoveryTerms  $terms
     * @return array<string, mixed>
     */
    private function purchaseTerms(array $terms, string $subscriptionId, string $planName): array
    {
        return [
            'plan_id' => $terms['plan_id'],
            'plan_slug' => $terms['plan_slug'],
            'plan_name' => $planName,
            'amount' => number_format($terms['amount_kobo'] / 100, 2, '.', ''),
            'currency' => strtoupper($terms['currency']),
            'billing_interval' => $terms['billing_interval'],
            'paystack_plan_code' => $terms['paystack_plan_code'],
            'subscription_id' => $subscriptionId,
            'terms_source' => 'explicit operator evidence validated against Paystack transaction and plan',
        ];
    }

    private function toMinorUnits(string $amount): int
    {
        $normalized = number_format((float) $amount, 2, '.', '');
        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '00');

        return ((int) $whole * 100) + (int) substr(str_pad($fraction, 2, '0'), 0, 2);
    }

    private function dateToIso(mixed $value): ?string
    {
        return $value === null ? null : Carbon::parse($value)->toISOString();
    }

    /**
     * @param  array<mixed>|string|null  $value
     * @return array<string, mixed>
     */
    private function decodeRecord(array|string|null $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if ($value === null) {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}

<?php

namespace App\Domains\Payment\Services;

use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Services\BusinessVatService;
use App\Domains\Payment\Contracts\PaymentGateway;
use App\Domains\Payment\Models\Payment;
use App\Domains\Payment\Models\SubscriptionCheckoutRecovery;
use App\Domains\Sales\Models\Sale;
use App\Domains\Subscription\Models\Subscription;
use App\Domains\Subscription\Models\SubscriptionPlan;
use App\Domains\Subscription\Services\SubscriptionPeriodCalculator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class PaymentConfirmationService
{
    public function __construct(
        private PaymentGateway $paymentGateway,
        private BusinessVatService $businessVatService,
        private PaymentService $paymentService,
        private PaystackSubscriptionProvisioningService $provisioningService,
    ) {}

    public function confirm(string $reference): Payment
    {
        $payment = Payment::query()->where('reference', $reference)->first();
        if (! $payment) {
            throw ValidationException::withMessages(['reference' => 'Payment not found.']);
        }

        if ($payment->status === 'paid') {
            $this->resumeProvisioning($payment);
            return $payment->fresh();
        }
        if (! in_array($payment->status, ['pending', 'failed'], true)) {
            throw ValidationException::withMessages(['payment' => 'Only pending payments can be confirmed.']);
        }

        $verifiedPaymentIdentity = $this->paymentIdentitySnapshot($payment);
        // Network verification and all validation happen before any transaction.
        $verified = $this->paymentGateway->verify($reference);
        if (! ($verified['success'] ?? false)) {
            throw ValidationException::withMessages(['payment' => 'Payment status could not be verified.']);
        }
        if (($verified['reference'] ?? null) !== $payment->reference) {
            throw ValidationException::withMessages(['reference' => 'Payment reference mismatch.']);
        }
        $plan = null;
        if (($payment->metadata['type'] ?? null) === 'subscription') {
            $plan = SubscriptionPlan::query()->whereKey($payment->metadata['plan_id'] ?? null)->first();
            $capturedCurrency = $payment->metadata['currency'] ?? null;
            $providerCurrency = strtoupper((string) ($verified['currency'] ?? ''));
            if ($providerCurrency === '') {
                throw ValidationException::withMessages(['currency' => 'Provider response did not include a currency.']);
            }
            if (filled($capturedCurrency) && $providerCurrency !== strtoupper((string) $capturedCurrency)) {
                throw ValidationException::withMessages(['currency' => 'Payment currency does not match the subscription plan.']);
            }
        } else {
            $expectedCurrency = strtoupper((string) ($payment->metadata['currency'] ?? 'NGN'));
            if (strtoupper((string) ($verified['currency'] ?? '')) !== $expectedCurrency) throw ValidationException::withMessages(['currency' => 'Payment currency does not match.']);
        }
        if (($verified['amount'] ?? null) !== $this->toMinorUnits((string) $payment->amount)) {
            throw ValidationException::withMessages(['amount' => 'Payment amount does not match.']);
        }
        if (($verified['status'] ?? null) !== 'success') {
            if (! in_array($verified['status'] ?? null, ['failed', 'abandoned'], true)) {
                return DB::transaction(function () use ($reference, $verified, $verifiedPaymentIdentity): Payment {
                    $locked = Payment::query()->where('reference', $reference)->lockForUpdate()->firstOrFail();
                    $this->assertPaymentIdentityUnchanged($locked, $verifiedPaymentIdentity);
                    if (in_array($locked->status, ['pending', 'failed'], true)) {
                        $providerStatus = is_string($verified['status'] ?? null) ? $verified['status'] : null;
                        $locked->forceFill([
                            'last_provider_status' => $providerStatus,
                            'last_provider_checked_at' => now(),
                        ])->save();
                        if ($locked->status === 'failed' && $locked->checkout_recovery_id) {
                            $recovery = SubscriptionCheckoutRecovery::query()
                                ->whereKey($locked->checkout_recovery_id)
                                ->lockForUpdate()
                                ->first();
                            if ($recovery && $recovery->status !== 'succeeded') {
                                $recovery->forceFill([
                                    'status' => 'manual_review',
                                    'manual_review_at' => $recovery->manual_review_at ?? now(),
                                    'manual_review_reason' => 'A previously failed attempt no longer has a definitive provider failure status.',
                                    'last_provider_status' => $providerStatus,
                                ])->save();
                            }
                        }
                    }
                    return $locked->fresh();
                });
            }
            return DB::transaction(function () use ($reference, $verified, $verifiedPaymentIdentity): Payment {
                $locked = Payment::query()->where('reference', $reference)->lockForUpdate()->firstOrFail();
                $this->assertPaymentIdentityUnchanged($locked, $verifiedPaymentIdentity);
                if (in_array($locked->status, ['pending', 'failed'], true)) {
                    $locked->forceFill([
                        'last_provider_status' => $verified['status'],
                        'last_provider_checked_at' => now(),
                    ])->save();
                }
                if ($locked->status === 'pending') {
                    $locked->forceFill(['status' => 'failed'])->save();
                    if ($locked->checkout_recovery_id) {
                        $recovery = SubscriptionCheckoutRecovery::query()
                            ->whereKey($locked->checkout_recovery_id)
                            ->lockForUpdate()
                            ->first();
                        if ($recovery && $recovery->winning_payment_id === null) {
                            $providerStatus = (string) ($verified['status'] ?? '');
                            $providerText = strtolower((string) ($verified['gateway_response'] ?? '') . ' ' . (string) ($verified['message'] ?? ''));
                            $retryReason = str_contains($providerText, 'session timeout')
                                || str_contains($providerText, 'exceeded the time to pay')
                                ? 'session_expired'
                                : ($providerStatus === 'abandoned' ? 'abandoned' : 'failed');
                            $otherPending = Payment::query()
                                ->where('checkout_recovery_id', $recovery->id)
                                ->where('status', 'pending')
                                ->where('id', '<>', $locked->id)
                                ->exists();
                            if (! $otherPending) {
                                $recovery->forceFill([
                                    'status' => 'retryable',
                                    'last_provider_status' => $providerStatus,
                                    'retry_reason' => $retryReason,
                                ])->save();
                            }
                        }
                    }
                }
                return $locked->fresh();
            });
        }

        // Capture and any safe local entitlement transition share one transaction.
        [$payment, $subscription] = DB::transaction(function () use (
            $reference,
            $verified,
            $plan,
            $payment,
            $verifiedPaymentIdentity,
        ): array {
            $business = Business::query()->whereKey($payment->business_id)->lockForUpdate()->firstOrFail();
            $locked = Payment::query()->where('reference', $reference)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'paid') return [$locked->fresh(), null];
            $this->assertPaymentIdentityUnchanged($locked, $verifiedPaymentIdentity);
            if ((string) $locked->business_id !== (string) $business->id) {
                throw ValidationException::withMessages(['payment' => 'Payment business changed during confirmation.']);
            }
            if (! in_array($locked->status, ['pending', 'failed'], true)) {
                throw ValidationException::withMessages(['payment' => 'Only pending payments can be confirmed.']);
            }

            $subscription = null;
            $entitlementReview = null;
            $recovery = null;
            if ($locked->checkout_recovery_id) {
                $recovery = SubscriptionCheckoutRecovery::query()
                    ->whereKey($locked->checkout_recovery_id)
                    ->lockForUpdate()
                    ->first();
                if (! $recovery
                    || (string) $recovery->business_id !== (string) $locked->business_id) {
                    $entitlementReview = 'Checkout recovery record could not be matched to the payment business.';
                } elseif ($recovery->winning_payment_id !== null
                    && (string) $recovery->winning_payment_id !== (string) $locked->id) {
                    $entitlementReview = 'Another payment attempt already fulfilled this checkout purchase.';
                } elseif ($recovery->status === 'manual_review') {
                    $entitlementReview = 'Checkout recovery is already held for manual review.';
                } else {
                    $terms = $recovery->purchase_terms ?? [];
                    $capturedPlanId = $locked->metadata['plan_id'] ?? null;
                    if ((string) $recovery->plan_id !== (string) $capturedPlanId
                        || (string) ($terms['plan_id'] ?? '') !== (string) $capturedPlanId
                        || (string) ($terms['amount'] ?? '') !== (string) $locked->amount
                        || strtoupper((string) ($terms['currency'] ?? '')) !== strtoupper((string) ($locked->metadata['currency'] ?? ''))
                        || (string) ($terms['billing_interval'] ?? '') !== (string) ($locked->metadata['billing_interval'] ?? '')
                        || (string) ($terms['paystack_plan_code'] ?? '') !== (string) ($locked->metadata['paystack_plan_code'] ?? '')
                        || (string) ($terms['subscription_id'] ?? '') !== (string) ($locked->metadata['subscription_id'] ?? $locked->subscription_id ?? '')
                        || ($recovery->subscription_id !== null && (string) $recovery->subscription_id !== (string) ($locked->subscription_id ?? ''))) {
                        $entitlementReview = 'Checkout recovery purchase terms do not match the captured payment terms.';
                    }
                }
            }
            if ($entitlementReview === null && ($locked->metadata['type'] ?? null) === 'subscription') {
                $metadata = $locked->metadata ?? [];
                $businessId = (string) $locked->business_id;
                if ((string) ($metadata['business_id'] ?? '') !== $businessId) {
                    $entitlementReview = 'Payment business does not match captured checkout business.';
                } elseif (! $plan
                    || ! filled($metadata['currency'] ?? null)
                    || empty($metadata['billing_interval'])
                    || ! filled($metadata['paystack_plan_code'] ?? null)
                    || ! filled($plan->paystack_plan_code)
                    || (string) $metadata['paystack_plan_code'] !== (string) $plan->paystack_plan_code) {
                    $entitlementReview = 'Captured subscription terms are incomplete or differ from the current plan.';
                } elseif ($locked->subscription_id !== null
                    && isset($metadata['subscription_id'])
                    && (string) $locked->subscription_id !== (string) $metadata['subscription_id']) {
                    $entitlementReview = 'Payment subscription association conflicts with captured checkout metadata.';
                } else {
                    $subscriptionId = $locked->subscription_id ?? ($metadata['subscription_id'] ?? null);
                    if ($subscriptionId) {
                        $subscription = Subscription::query()
                            ->whereKey($subscriptionId)
                            ->where('business_id', $businessId)
                            ->lockForUpdate()
                            ->first();
                    } else {
                        $hasExisting = Subscription::query()->where('business_id', $businessId)->exists();
                        if (! $hasExisting) {
                            $subscription = new Subscription(['business_id' => $businessId]);
                            $subscription->status = 'trial';
                        }
                    }

                    if (! $subscription) {
                        $entitlementReview = 'The exact checkout subscription could not be matched to this payment business.';
                    } else {
                        $isFreeUpgrade = $subscription->status === 'active'
                            && $subscriptionId !== null
                            && (string) $locked->subscription_id === (string) $subscription->id
                            && $subscription->plan !== null
                            && $subscription->plan->slug === 'free'
                            && preg_match('/^0+(?:\.0+)?$/', (string) $subscription->plan->price) === 1;
                        $eligible = in_array($subscription->status, ['trial', 'past_due', 'grace_period'], true) || $isFreeUpgrade;
                        if (! $eligible || filled($subscription->provider_subscription_code)) {
                            $entitlementReview = 'The matched subscription is not eligible for this checkout transition.';
                            $subscription = null;
                        }
                    }
                }
            }

            $locked->forceFill(['status' => 'paid', 'paid_at' => now()])->save();
            $locked->forceFill([
                'last_provider_status' => 'success',
                'last_provider_checked_at' => now(),
            ])->save();
            if ($entitlementReview !== null) {
                $locked->forceFill(['metadata' => array_merge($locked->metadata ?? [], [
                    'entitlement_review' => $entitlementReview,
                ])])->save();
            } elseif ($subscription && $plan) {
                $now = now();
                $subscription->forceFill([
                    'plan_id' => $plan->id,
                    'status' => 'active',
                    'provider' => 'paystack',
                    'provider_customer_code' => $verified['customer_code'] ?? null,
                    'provider_authorization_code' => $verified['authorization_code'] ?? null,
                    'starts_at' => $subscription->starts_at ?? $now,
                    'current_period_start' => $now,
                    'current_period_end' => app(SubscriptionPeriodCalculator::class)->endFor($now, $locked->metadata['billing_interval']),
                    'provisioning_status' => 'pending',
                    'grace_period_ends_at' => null,
                    'cancelled_at' => null,
                    'ended_at' => null,
                ])->save();
                $locked->forceFill(['subscription_id' => $subscription->id])->save();
                $this->businessVatService->applyDefault($business, $plan);
                $subscription = $subscription->fresh();
            }
            if ($recovery) {
                if ($entitlementReview !== null) {
                    $recovery->forceFill([
                        'status' => 'manual_review',
                        'manual_review_at' => $recovery->manual_review_at ?? now(),
                        'manual_review_reason' => $entitlementReview,
                    ])->save();
                } elseif ($recovery->winning_payment_id === null) {
                    $recovery->forceFill([
                        'status' => 'succeeded',
                        'winning_payment_id' => $locked->id,
                    ])->save();
                }
            }
            if ($locked->sale_id !== null) {
                $sale = Sale::query()->whereKey($locked->sale_id)->where('business_id', $locked->business_id)->lockForUpdate()->first();
                if (! $sale) throw ValidationException::withMessages(['sale' => 'The payment sale could not be found.']);
                $this->paymentService->syncSalePaymentStatus($sale);
            }
            return [$locked->fresh(), $subscription];
        });

        if ($subscription) {
            try { $this->provisioningService->provision($subscription); } catch (Throwable) { /* Captured payment remains paid; provisioning state is persisted by the owner. */ }
        }
        return $payment->fresh();
    }

    private function resumeProvisioning(Payment $payment): void
    {
        if (($payment->metadata['type'] ?? null) !== 'subscription') return;
        if (! empty($payment->metadata['entitlement_review'])) return;
        if ($payment->checkout_recovery_id) {
            $recovery = SubscriptionCheckoutRecovery::query()->find($payment->checkout_recovery_id);
            if (! $recovery
                || $recovery->status === 'manual_review'
                || (string) $recovery->winning_payment_id !== (string) $payment->id) return;
        }
        $subscriptionId = $payment->subscription_id ?? ($payment->metadata['subscription_id'] ?? null);
        if (! $subscriptionId) return;
        $subscription = Subscription::query()->find($subscriptionId);
        if (! $subscription
            || (string) $subscription->business_id !== (string) $payment->business_id
            || (isset($payment->metadata['plan_id'])
                && (string) $subscription->plan_id !== (string) $payment->metadata['plan_id'])) return;
        if (! in_array($subscription->status, ['trial', 'past_due', 'grace_period', 'active'], true)) return;
        if ($subscription->status === 'active' && $subscription->provisioning_status === 'not_required') return;
        if (! in_array($subscription->provisioning_status, ['pending', 'creating', 'reconciliation_required'], true)) return;
        try { $this->provisioningService->provision($subscription); } catch (Throwable) { /* Payment remains captured; provisioning state is persisted by the owner. */ }
    }

    private function toMinorUnits(string $amount): int
    {
        $normalized = number_format((float) $amount, 2, '.', '');
        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '00');
        return ((int) $whole * 100) + (int) substr(str_pad($fraction, 2, '0'), 0, 2);
    }

    private function assertPaymentIdentityUnchanged(Payment $payment, string $verifiedIdentity): void
    {
        if (! hash_equals($verifiedIdentity, $this->paymentIdentitySnapshot($payment))) {
            throw ValidationException::withMessages([
                'payment' => 'Payment details changed during provider verification. Reconcile the transaction before continuing.',
            ]);
        }
    }

    private function paymentIdentitySnapshot(Payment $payment): string
    {
        $metadata = $payment->metadata ?? [];
        $capturedTerms = [];
        foreach ([
            'type', 'business_id', 'subscription_id', 'plan_id', 'plan_slug',
            'billing_interval', 'currency', 'paystack_plan_code',
        ] as $key) {
            $capturedTerms[$key] = [
                'present' => array_key_exists($key, $metadata),
                'value' => $metadata[$key] ?? null,
            ];
        }

        return hash('sha256', json_encode([
            'id' => (string) $payment->id,
            'business_id' => (string) $payment->business_id,
            'sale_id' => $payment->sale_id === null ? null : (string) $payment->sale_id,
            'subscription_id' => $payment->subscription_id === null ? null : (string) $payment->subscription_id,
            'amount' => (string) $payment->amount,
            'method' => (string) $payment->method,
            'reference' => (string) $payment->reference,
            'checkout_recovery_id' => $payment->checkout_recovery_id === null ? null : (string) $payment->checkout_recovery_id,
            'attempt_number' => $payment->attempt_number === null ? null : (int) $payment->attempt_number,
            'metadata' => $capturedTerms,
        ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }
}

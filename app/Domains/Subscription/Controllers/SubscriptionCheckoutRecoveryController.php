<?php

namespace App\Domains\Subscription\Controllers;

use App\Domains\Organization\Services\BusinessContextService;
use App\Domains\Payment\Models\SubscriptionCheckoutRecovery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionCheckoutRecoveryController
{
    public function __construct(private BusinessContextService $businessContext) {}

    public function index(Request $request): JsonResponse
    {
        $business = $this->businessContext->current($request->user());
        abort_unless($business, 400, 'Business context is required.');

        $recoveries = SubscriptionCheckoutRecovery::query()
            ->where('business_id', $business->id)
            ->with([
                'payments:id,checkout_recovery_id,attempt_number,reference,status,amount,created_at,paid_at,metadata,last_provider_status',
                'plan:id,name,slug,price,currency,billing_interval,paystack_plan_code,is_active',
                'subscription:id,business_id,plan_id,status',
            ])
            ->latest('created_at')
            ->limit(25)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $recoveries->map(function (SubscriptionCheckoutRecovery $recovery): array {
                $hasPaid = $recovery->payments->contains(fn ($payment): bool => $payment->status === 'paid');
                $hasPending = $recovery->payments->contains(fn ($payment): bool => $payment->status === 'pending');
                $hasManualPaymentReview = $recovery->payments->contains(fn ($payment): bool => filled(data_get($payment->metadata, 'entitlement_review')));
                $allFailuresDefinitive = $recovery->payments->isNotEmpty() && $recovery->payments->every(fn ($payment): bool => $payment->status === 'failed' && in_array($payment->last_provider_status, ['failed', 'abandoned'], true)
                );
                $purchaseTerms = $recovery->purchase_terms ?? [];
                $termsMatchCurrentPlan = $recovery->plan !== null
                    && $recovery->plan->is_active
                    && (string) $recovery->plan_id === (string) $recovery->plan->id
                    && (string) data_get($purchaseTerms, 'plan_id') === (string) $recovery->plan->id
                    && (string) data_get($purchaseTerms, 'amount') === (string) $recovery->plan->price
                    && strtoupper((string) data_get($purchaseTerms, 'currency')) === strtoupper((string) $recovery->plan->currency)
                    && (string) data_get($purchaseTerms, 'billing_interval') === (string) $recovery->plan->billing_interval
                    && (string) data_get($purchaseTerms, 'paystack_plan_code', '') === (string) ($recovery->plan->paystack_plan_code ?? '')
                    && (string) data_get($purchaseTerms, 'subscription_id', '') === (string) ($recovery->subscription?->id ?? '');
                $requiresEntitlementRecovery = $hasPaid
                    && ($hasManualPaymentReview
                        || (string) ($recovery->subscription?->plan_id ?? '') !== (string) $recovery->plan_id);
                $retryAllowed = $recovery->status === 'retryable'
                    && $recovery->winning_payment_id === null
                    && ! $hasPaid
                    && ! $hasPending
                    && ! $hasManualPaymentReview
                    && $allFailuresDefinitive
                    && $termsMatchCurrentPlan;

                return [
                    'id' => $recovery->id,
                    'status' => $recovery->status,
                    'retry_allowed' => $retryAllowed,
                    'requires_entitlement_recovery' => $requiresEntitlementRecovery,
                    'plan' => $recovery->plan ? [
                        'id' => $recovery->plan->id,
                        'name' => $recovery->plan->name,
                        'slug' => $recovery->plan->slug,
                    ] : null,
                    'purchase_terms' => [
                        'plan_id' => data_get($recovery->purchase_terms, 'plan_id'),
                        'plan_slug' => data_get($recovery->purchase_terms, 'plan_slug'),
                        'plan_name' => data_get($recovery->purchase_terms, 'plan_name'),
                        'amount' => data_get($recovery->purchase_terms, 'amount'),
                        'currency' => data_get($recovery->purchase_terms, 'currency'),
                        'billing_interval' => data_get($recovery->purchase_terms, 'billing_interval'),
                    ],
                    'winning_payment_id' => $recovery->winning_payment_id,
                    'manual_review' => $recovery->status === 'manual_review' || $hasManualPaymentReview,
                    'created_at' => $recovery->created_at?->toIso8601String(),
                    'attempts' => $recovery->payments->map(fn ($payment): array => [
                        'payment_id' => $payment->id,
                        'attempt_number' => $payment->attempt_number,
                        'reference' => $payment->reference,
                        'status' => $payment->status,
                        'amount' => $payment->amount,
                        'created_at' => $payment->created_at?->toIso8601String(),
                        'paid_at' => $payment->paid_at?->toIso8601String(),
                        'last_provider_status' => $payment->last_provider_status,
                        'stored_authorization_url' => $payment->status === 'pending'
                            && filled(data_get($payment->metadata, 'checkout.authorization_url')),
                    ]),
                ];
            }),
        ]);
    }
}

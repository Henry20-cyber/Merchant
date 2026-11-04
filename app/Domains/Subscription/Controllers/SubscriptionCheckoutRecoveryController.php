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
            ->with(['payments:id,checkout_recovery_id,attempt_number,reference,status,amount,created_at,paid_at,metadata'])
            ->latest('created_at')
            ->limit(25)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $recoveries->map(fn (SubscriptionCheckoutRecovery $recovery): array => [
                'id' => $recovery->id,
                'status' => $recovery->status,
                'purchase_terms' => [
                    'plan_id' => data_get($recovery->purchase_terms, 'plan_id'),
                    'plan_slug' => data_get($recovery->purchase_terms, 'plan_slug'),
                    'plan_name' => data_get($recovery->purchase_terms, 'plan_name'),
                    'amount' => data_get($recovery->purchase_terms, 'amount'),
                    'currency' => data_get($recovery->purchase_terms, 'currency'),
                    'billing_interval' => data_get($recovery->purchase_terms, 'billing_interval'),
                ],
                'winning_payment_id' => $recovery->winning_payment_id,
                'manual_review' => $recovery->status === 'manual_review',
                'created_at' => $recovery->created_at?->toIso8601String(),
                'attempts' => $recovery->payments->map(fn ($payment): array => [
                    'payment_id' => $payment->id,
                    'attempt_number' => $payment->attempt_number,
                    'reference' => $payment->reference,
                    'status' => $payment->status,
                    'amount' => $payment->amount,
                    'created_at' => $payment->created_at?->toIso8601String(),
                    'paid_at' => $payment->paid_at?->toIso8601String(),
                    'stored_authorization_url' => $payment->status === 'pending'
                        && filled(data_get($payment->metadata, 'checkout.authorization_url')),
                ]),
            ]),
        ]);
    }
}

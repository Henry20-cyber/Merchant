<?php

namespace App\Domains\Subscription\Controllers;

use App\Domains\Payment\Models\Payment;
use App\Domains\Payment\Services\PaymentConfirmationService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Throwable;

class SubscriptionPaymentVerificationController extends Controller
{
    public function verify(Request $request): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'reference' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9_-]+$/'],
        ])->validate();
        $business = $this->currentBusiness($request);
        $payment = Payment::query()->where('business_id', $business->id)
            ->where('reference', $validated['reference'])->first();
        if (! $payment || $payment->method !== 'paystack' || ($payment->metadata['type'] ?? null) !== 'subscription') {
            return response()->json(['success' => false, 'message' => 'Subscription payment reference was not found.'], 404);
        }

        try {
            // Resolve the provider-backed confirmation service only after
            // validation and business-scoped payment lookup have succeeded.
            $payment = app(PaymentConfirmationService::class)->confirm($payment->reference);
        } catch (Throwable $exception) {
            report($exception);
            return response()->json([
                'success' => true,
                'data' => ['status' => 'inconclusive', 'payment_status' => $payment->fresh()->status, 'subscription' => null],
                'message' => 'Payment verification is still processing. Please check again shortly.',
            ], 202);
        }

        $payment->load('subscription');
        $subscription = $payment->subscription;
        $recovery = $payment->checkoutRecovery;
        return response()->json([
            'success' => true,
            'data' => [
                'status' => $recovery?->status === 'manual_review' || filled(data_get($payment->metadata, 'entitlement_review'))
                    ? 'manual_review'
                    : ($payment->status === 'paid' ? 'succeeded' : ($payment->status === 'failed' ? 'failed' : 'processing')),
                'payment_status' => $payment->status,
                'subscription' => $subscription ? ['id' => $subscription->id, 'status' => $subscription->status, 'plan_id' => $subscription->plan_id, 'provisioning_status' => $subscription->provisioning_status] : null,
            ],
        ]);
    }
}

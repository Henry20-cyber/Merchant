<?php

namespace App\Http\Controllers\Api;

use App\Domains\Payment\Models\Payment;
use App\Domains\Payment\Models\SubscriptionCheckoutRecovery;
use App\Domains\Payment\Services\PaymentConfirmationService;
use App\Domains\Subscription\Models\SubscriptionPlan;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FounderPaymentRecoveryController extends Controller
{
    public function __construct(private PaymentConfirmationService $confirmationService) {}

    public function index(): JsonResponse
    {
        $items = SubscriptionCheckoutRecovery::query()
            ->where('status', 'manual_review')
            ->with(['business:id,name', 'payments:id,checkout_recovery_id,attempt_number,reference,status,amount,created_at,paid_at'])
            ->latest('manual_review_at')
            ->paginate(50);

        return response()->json(['success' => true, 'data' => $items]);
    }

    public function resolve(Request $request, string $recoveryId): JsonResponse
    {
        $validated = $request->validate([
            'decision' => ['required', 'in:record_only,allow_new_attempt'],
            'resolution' => ['required', 'string', 'min:8', 'max:2000'],
        ]);

        $verifiedAttemptTimestamps = [];
        $verifiedAttemptSnapshots = [];
        $verifiedAttemptIdentities = [];
        $candidateRecoverySnapshot = null;
        if ($validated['decision'] === 'allow_new_attempt') {
            $candidate = SubscriptionCheckoutRecovery::query()->with('payments')->findOrFail($recoveryId);
            $candidateRecoverySnapshot = $this->modelSnapshot($candidate);
            abort_unless(
                $candidate->status === 'manual_review'
                    && $candidate->manual_review_resolved_at === null
                    && $candidate->winning_payment_id === null
                    && $candidate->payments->isNotEmpty()
                    && $candidate->payments->every(fn ($payment): bool => $payment->status === 'failed'),
                409,
                'A new attempt is safe only when every existing attempt is failed and no winner exists.'
            );

            foreach ($candidate->payments as $payment) {
                $candidateIdentity = $this->paymentIdentitySnapshot($payment);
                try {
                    $checked = $this->confirmationService->confirm((string) $payment->reference);
                } catch (\Throwable) {
                    abort(409, 'The provider outcome could not be reverified; the recovery remains under review.');
                }

                if ($checked->status !== 'failed'
                    || ! in_array($checked->last_provider_status, ['failed', 'abandoned'], true)
                    || ! $checked->last_provider_checked_at) {
                    abort(409, 'A previous attempt is not definitively failed; the recovery remains under review.');
                }
                if ($this->paymentIdentitySnapshot($checked) !== $candidateIdentity) {
                    abort(409, 'A payment attempt changed during provider verification; the recovery remains under review.');
                }

                $verifiedAttemptTimestamps[$checked->id] = $checked->last_provider_checked_at->format('Y-m-d H:i:s.uP');
                $verifiedAttemptSnapshots[$checked->id] = $this->modelSnapshot($checked);
                $verifiedAttemptIdentities[$checked->id] = $candidateIdentity;
            }
        }

        $recovery = DB::transaction(function () use (
            $request,
            $recoveryId,
            $validated,
            $verifiedAttemptTimestamps,
            $verifiedAttemptSnapshots,
            $verifiedAttemptIdentities,
            $candidateRecoverySnapshot,
        ): SubscriptionCheckoutRecovery {
            $attempts = collect();
            if ($validated['decision'] === 'allow_new_attempt') {
                $attempts = Payment::query()
                    ->whereIn('id', array_keys($verifiedAttemptTimestamps))
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
            }

            $recovery = SubscriptionCheckoutRecovery::query()
                ->whereKey($recoveryId)
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless(
                $recovery->status === 'manual_review' && $recovery->manual_review_resolved_at === null,
                409,
                'Recovery is not awaiting manual review.'
            );

            if ($validated['decision'] === 'allow_new_attempt') {
                $recovery->load('payments');
                $payments = $recovery->payments;
                $verifiedIds = array_keys($verifiedAttemptTimestamps);
                $currentIds = $payments->modelKeys();
                sort($verifiedIds, SORT_STRING);
                sort($currentIds, SORT_STRING);
                $sameAttemptSet = $verifiedIds === $currentIds;
                $allDefinitivelyFailed = $sameAttemptSet
                    && $this->modelSnapshot($recovery) === $candidateRecoverySnapshot
                    && $attempts->isNotEmpty()
                    && $attempts->every(fn ($payment): bool => $payment->status === 'failed'
                        && in_array($payment->last_provider_status, ['failed', 'abandoned'], true)
                        && $payment->last_provider_checked_at?->format('Y-m-d H:i:s.uP') === ($verifiedAttemptTimestamps[$payment->id] ?? null)
                        && $this->modelSnapshot($payment) === ($verifiedAttemptSnapshots[$payment->id] ?? null)
                        && $this->paymentIdentitySnapshot($payment) === ($verifiedAttemptIdentities[$payment->id] ?? null))
                    && $recovery->winning_payment_id === null;
                abort_unless($allDefinitivelyFailed, 409, 'A new attempt is safe only after every existing payment attempt is definitively failed.');

                $terms = $recovery->purchase_terms ?? [];
                $plan = $recovery->plan_id
                    ? SubscriptionPlan::query()->whereKey($recovery->plan_id)->lockForUpdate()->first()
                    : null;
                $termsMatch = $plan
                    && $plan->is_active
                    && (string) ($terms['plan_id'] ?? '') === (string) $plan->id
                    && (string) ($terms['amount'] ?? '') === (string) $plan->price
                    && strtoupper((string) ($terms['currency'] ?? '')) === strtoupper((string) $plan->currency)
                    && (string) ($terms['billing_interval'] ?? '') === (string) $plan->billing_interval
                    && (string) ($terms['paystack_plan_code'] ?? '') === (string) $plan->paystack_plan_code;
                abort_unless($termsMatch, 409, 'Captured purchase terms are incomplete or have changed; do not retry this recovery group.');
            }

            $recovery->forceFill([
                'status' => $validated['decision'] === 'allow_new_attempt' ? 'retryable' : 'manual_review',
                'manual_review_resolution' => trim($validated['resolution']),
                'manual_review_resolved_at' => $validated['decision'] === 'allow_new_attempt' ? now() : null,
                'manual_review_resolved_by' => $validated['decision'] === 'allow_new_attempt' ? $request->user()->id : null,
            ])->save();

            return $recovery->fresh();
        });

        return response()->json(['success' => true, 'data' => [
            'id' => $recovery->id,
            'status' => $recovery->status,
            'manual_review_resolved_at' => $recovery->manual_review_resolved_at?->toIso8601String(),
        ]]);
    }

    private function modelSnapshot(\Illuminate\Database\Eloquent\Model $model): string
    {
        $attributes = $model->getAttributes();
        ksort($attributes);

        return hash('sha256', json_encode($attributes, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function paymentIdentitySnapshot(Payment $payment): string
    {
        $metadata = $payment->metadata ?? [];
        $terms = [];
        foreach ([
            'type', 'business_id', 'subscription_id', 'plan_id', 'plan_slug',
            'billing_interval', 'currency', 'paystack_plan_code',
        ] as $key) {
            $terms[$key] = [
                'present' => array_key_exists($key, $metadata),
                'value' => $metadata[$key] ?? null,
            ];
        }

        return hash('sha256', json_encode([
            'id' => (string) $payment->id,
            'business_id' => (string) $payment->business_id,
            'subscription_id' => $payment->subscription_id === null ? null : (string) $payment->subscription_id,
            'sale_id' => $payment->sale_id === null ? null : (string) $payment->sale_id,
            'amount' => (string) $payment->amount,
            'method' => (string) $payment->method,
            'status' => (string) $payment->status,
            'reference' => (string) $payment->reference,
            'checkout_recovery_id' => $payment->checkout_recovery_id === null ? null : (string) $payment->checkout_recovery_id,
            'attempt_number' => $payment->attempt_number === null ? null : (int) $payment->attempt_number,
            'metadata' => $terms,
        ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }
}

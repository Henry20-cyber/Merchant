<?php

namespace App\Domains\Payment\Services;

use App\Domains\Payment\Models\Payment;
use App\Domains\Subscription\Models\Subscription;
use App\Domains\Subscription\Models\SubscriptionPlan;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubscriptionRenewalService
{
    /** Process a successful recurring Paystack charge idempotently. */
    public function renew(array $data): Payment
    {
        try {
            return DB::transaction(fn (): Payment => $this->processRenewal($data));
        } catch (QueryException $exception) {
            if (! $this->isPaymentReferenceConflict($exception)) {
                throw $exception;
            }

            // DB::transaction has rolled back before this query. PostgreSQL
            // marks a transaction failed after a unique violation, so the
            // winner must be loaded outside that transaction.
            return $this->recoverPaymentAfterReferenceConflict($data, $exception);
        }
    }

    private function recoverPaymentAfterReferenceConflict(array $data, QueryException $exception): Payment
    {
        $winner = Payment::query()
            ->where('reference', $data['reference'] ?? null)
            ->first();
        if (! $winner) throw $exception;

        $this->validateExistingPayment($winner, $data);
        return $winner;
    }

    private function processRenewal(array $data): Payment
    {
        $context = $this->resolveRenewalContext($data, true, false);
        /** @var Subscription $subscription */
        $subscription = $context['subscription'];
        /** @var SubscriptionPlan $plan */
        $plan = $context['plan'];
        $reference = $context['reference'];

        $existing = Payment::query()
            ->where('reference', $reference)
            ->lockForUpdate()
            ->first();
        if ($existing) {
            $this->validateExistingPayment($existing, $data, $subscription, $plan);
            return $existing->refresh();
        }

        if (! $plan->is_active) {
            throw ValidationException::withMessages(['plan' => 'The subscription plan is not active.']);
        }

        $providerPaidAt = $this->parseProviderPaidAt(data_get($data, 'paid_at'));

        $payment = Payment::create([
            'business_id' => $subscription->business_id,
            'sale_id' => null,
            'subscription_id' => $subscription->id,
            'amount' => $plan->price,
            'method' => 'paystack',
            'status' => 'paid',
            'reference' => $reference,
            'metadata' => [
                'type' => 'subscription_renewal',
                'provider' => 'paystack',
                'customer_code' => $context['customer_code'],
                'subscription_code' => $context['subscription_code'],
                'plan_id' => $plan->id,
                'currency' => strtoupper((string) $plan->currency),
                'paid_at_source' => $providerPaidAt === null ? 'local_fallback' : 'provider',
            ],
            'paid_at' => $providerPaidAt ?? now(),
        ]);

        // A delayed successful charge after cancellation/suspension is real
        // money, but must not revive terminal access.
        if (in_array($subscription->status, ['cancelled', 'suspended'], true)) {
            return $payment->refresh();
        }

        $periodStart = $subscription->current_period_end
            ? $subscription->current_period_end->copy()
            : now();
        $periodEnd = app(\App\Domains\Subscription\Services\SubscriptionPeriodCalculator::class)
            ->endFor($periodStart, $plan->billing_interval);
        $renewalEnabled = (bool) $subscription->auto_renew;

        $subscription->forceFill([
            'status' => 'active',
            'current_period_start' => $periodStart,
            'current_period_end' => $periodEnd,
            'grace_period_ends_at' => null,
            'restricted_at' => null,
            'cancelled_at' => $renewalEnabled ? null : $subscription->cancelled_at,
            'ended_at' => null,
            'auto_renew' => $renewalEnabled,
        ])->save();

        return $payment->refresh();
    }

    /** @return array{reference: string, customer_code: string, subscription_code: string, subscription: Subscription, plan: SubscriptionPlan} */
    private function resolveRenewalContext(array $data, bool $lock, bool $requireActivePlan = true): array
    {
        $reference = data_get($data, 'reference');
        $customerCode = data_get($data, 'customer_code');
        $subscriptionCode = data_get($data, 'subscription_code');

        if (! is_string($reference) || trim($reference) === '') {
            throw ValidationException::withMessages(['reference' => 'Payment reference is required.']);
        }
        if (! is_string($customerCode) || trim($customerCode) === '') {
            throw ValidationException::withMessages(['customer' => 'Paystack customer code is missing.']);
        }
        if (! is_string($subscriptionCode) || trim($subscriptionCode) === '') {
            throw ValidationException::withMessages(['subscription' => 'Paystack subscription code is missing.']);
        }

        $query = Subscription::query()
            ->where('provider', 'paystack')
            ->where('provider_customer_code', $customerCode)
            ->where('provider_subscription_code', $subscriptionCode);
        if ($lock) $query->lockForUpdate();
        $subscription = $query->first();
        if (! $subscription) {
            throw ValidationException::withMessages([
                'subscription' => 'MerchantOS subscription could not be matched to the Paystack subscription.',
            ]);
        }

        $plan = $subscription->plan;
        if (! $plan || ($requireActivePlan && ! $plan->is_active)) {
            throw ValidationException::withMessages(['plan' => 'The subscription plan is not active.']);
        }

        $amount = data_get($data, 'amount');
        if (! (is_int($amount) || (is_string($amount) && preg_match('/\A\d+\z/D', $amount) === 1))
            || (int) $amount !== $this->toMinorUnits((string) $plan->price)) {
            throw ValidationException::withMessages([
                'amount' => 'Recurring payment amount does not match the subscription plan.',
            ]);
        }

        $currency = strtoupper((string) data_get($data, 'currency', ''));
        if ($currency === '' || $currency !== strtoupper((string) $plan->currency)) {
            throw ValidationException::withMessages([
                'currency' => 'Recurring payment currency does not match the subscription plan.',
            ]);
        }

        return [
            'reference' => $reference,
            'customer_code' => $customerCode,
            'subscription_code' => $subscriptionCode,
            'subscription' => $subscription,
            'plan' => $plan,
        ];
    }

    private function validateExistingPayment(
        Payment $payment,
        array $data,
        ?Subscription $subscription = null,
        ?SubscriptionPlan $plan = null,
    ): void {
        $context = $this->resolveRenewalContext($data, false, false);
        $subscription ??= $context['subscription'];
        $plan ??= $context['plan'];
        $metadata = $payment->metadata ?? [];
        $incomingReference = data_get($data, 'reference');
        $metadataMatches = ($metadata['type'] ?? null) === 'subscription_renewal'
            && ($metadata['provider'] ?? null) === 'paystack'
            && (string) ($metadata['customer_code'] ?? '') === $context['customer_code']
            && (string) ($metadata['subscription_code'] ?? '') === $context['subscription_code']
            && (string) ($metadata['plan_id'] ?? '') === (string) $plan->id
            && (! isset($metadata['currency'])
                || strtoupper((string) $metadata['currency']) === strtoupper((string) $plan->currency));

        $paymentAmountMatches = false;
        try {
            $paymentAmountMatches = $this->toMinorUnits((string) $payment->amount)
                === $this->toMinorUnits((string) $plan->price);
        } catch (ValidationException) {
            $paymentAmountMatches = false;
        }

        if ((string) $payment->reference !== (string) $incomingReference
            || (string) $payment->business_id !== (string) $subscription->business_id
            || (string) $payment->subscription_id !== (string) $subscription->id
            || $payment->sale_id !== null
            || $payment->method !== 'paystack'
            || $payment->status !== 'paid'
            || ! $paymentAmountMatches
            || strtoupper((string) data_get($data, 'currency', '')) !== strtoupper((string) $plan->currency)
            || ! $metadataMatches) {
            throw ValidationException::withMessages([
                'reference' => 'This payment reference is already associated with a different or incomplete transaction.',
            ]);
        }
    }

    private function isPaymentReferenceConflict(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        if ($sqlState !== '23505') return false;

        $errorInfoMessage = (string) ($exception->errorInfo[2] ?? '');
        $message = $errorInfoMessage . ' ' . $exception->getMessage();
        return str_contains($message, 'payments_reference_unique');
    }

    private function parseProviderPaidAt(mixed $value): ?Carbon
    {
        if (! is_string($value)
            || preg_match('/\A(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})\z/', $value, $parts) !== 1
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
            || (int) $parts[4] > 23
            || (int) $parts[5] > 59
            || (int) $parts[6] > 59) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Convert a major-unit amount into provider minor units. */
    private function toMinorUnits(string $amount): int
    {
        $amount = trim($amount);
        if (! preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $amount, $matches)) {
            throw ValidationException::withMessages(['amount' => 'The subscription plan amount is invalid.']);
        }
        $whole = (int) $matches[1];
        $fraction = (int) str_pad($matches[2] ?? '', 2, '0');
        return ($whole * 100) + $fraction;
    }
}

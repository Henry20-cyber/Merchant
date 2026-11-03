<?php

namespace App\Domains\Payment\Services;

use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Services\BusinessVatService;
use App\Domains\Payment\Contracts\PaymentGateway;
use App\Domains\Payment\Models\Payment;
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
        if ($payment->status !== 'pending') {
            throw ValidationException::withMessages(['payment' => 'Only pending payments can be confirmed.']);
        }

        // Network verification and all validation happen before any transaction.
        $verified = $this->paymentGateway->verify($reference);
        if (! ($verified['success'] ?? false) || ($verified['status'] ?? null) !== 'success') {
            return DB::transaction(function () use ($reference): Payment {
                $locked = Payment::query()->where('reference', $reference)->lockForUpdate()->firstOrFail();
                if ($locked->status === 'pending') {
                    $locked->forceFill(['status' => 'failed'])->save();
                }
                return $locked->fresh();
            });
        }

        if (($verified['reference'] ?? null) !== $payment->reference) {
            throw ValidationException::withMessages(['reference' => 'Payment reference mismatch.']);
        }
        if (($verified['amount'] ?? null) !== $this->toMinorUnits((string) $payment->amount)) {
            throw ValidationException::withMessages(['amount' => 'Payment amount does not match.']);
        }

        $plan = null;
        if (($payment->metadata['type'] ?? null) === 'subscription') {
            $planId = $payment->metadata['plan_id'] ?? null;
            $plan = SubscriptionPlan::query()->whereKey($planId)->where('is_active', true)->first();
            if (! $plan) {
                throw ValidationException::withMessages(['plan' => 'The subscription plan is no longer available.']);
            }
            if (strtoupper((string) ($verified['currency'] ?? '')) !== strtoupper((string) $plan->currency)) {
                throw ValidationException::withMessages(['currency' => 'Payment currency does not match the subscription plan.']);
            }
            if (($verified['amount'] ?? null) !== $this->toMinorUnits((string) $plan->price)) {
                throw ValidationException::withMessages(['amount' => 'Payment amount does not match the selected subscription plan.']);
            }
        } else {
            $expectedCurrency = strtoupper((string) ($payment->metadata['currency'] ?? 'NGN'));
            if (strtoupper((string) ($verified['currency'] ?? '')) !== $expectedCurrency) {
                throw ValidationException::withMessages(['currency' => 'Payment currency does not match.']);
            }
        }

        // Payment capture is committed independently of local subscription preparation and provider work.
        $newlyPaid = false;
        $payment = DB::transaction(function () use ($reference, &$newlyPaid): Payment {
            $locked = Payment::query()->where('reference', $reference)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'paid') return $locked->fresh();
            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages(['payment' => 'Only pending payments can be confirmed.']);
            }
            $locked->forceFill(['status' => 'paid', 'paid_at' => now()])->save();
            $newlyPaid = true;
            if ($locked->sale_id !== null) {
                $sale = Sale::query()->whereKey($locked->sale_id)->where('business_id', $locked->business_id)->lockForUpdate()->first();
                if (! $sale) throw ValidationException::withMessages(['sale' => 'The payment sale could not be found.']);
                $this->paymentService->syncSalePaymentStatus($sale);
            }
            return $locked->fresh();
        });

        if ($plan && $newlyPaid) {
            $this->prepareAndProvision($payment, $plan, $verified);
        } elseif ($plan) {
            $this->resumeProvisioning($payment);
        }
        return $payment->fresh();
    }

    private function resumeProvisioning(Payment $payment): void
    {
        if (($payment->metadata['type'] ?? null) !== 'subscription') return;
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

    private function prepareAndProvision(Payment $payment, SubscriptionPlan $plan, array $verified): void
    {
        $metadata = $payment->metadata ?? [];
        $businessId = $metadata['business_id'] ?? $payment->business_id;
        if (! $businessId
            || (string) $businessId !== (string) $payment->business_id
            || (float) $plan->price <= 0) return;

        $subscription = DB::transaction(function () use ($payment, $plan, $verified, $metadata, $businessId): ?Subscription {
            $exactId = $payment->subscription_id ?? ($metadata['subscription_id'] ?? null);
            $query = Subscription::query()->where('business_id', $businessId);
            if ($exactId) $query->whereKey($exactId);
            $subscription = $query->lockForUpdate()->first();

            if (! $subscription && ! $exactId) {
                // Only create for a genuinely first checkout; never pick another business subscription implicitly.
                if (Subscription::query()->where('business_id', $businessId)->exists()) return null;
                $now = now();
                $subscription = new Subscription(['business_id' => $businessId]);
                $subscription->status = 'trial';
            }
            if (! $subscription || ! in_array($subscription->status, ['trial', 'past_due', 'grace_period'], true)) return null;
            if (filled($subscription->provider_subscription_code)) return null;

            $now = now();
            $subscription->forceFill([
                'plan_id' => $plan->id,
                'status' => 'active',
                'provider' => 'paystack',
                'provider_customer_code' => $verified['customer_code'] ?? null,
                'provider_authorization_code' => $verified['authorization_code'] ?? null,
                'starts_at' => $subscription->starts_at ?? $now,
                'current_period_start' => $now,
                'current_period_end' => app(SubscriptionPeriodCalculator::class)->endFor($now, $plan->billing_interval),
                'provisioning_status' => 'pending',
                'grace_period_ends_at' => null,
                'cancelled_at' => null,
                'ended_at' => null,
            ])->save();
            $payment->forceFill(['subscription_id' => $subscription->id])->save();
            $business = Business::query()->whereKey($businessId)->lockForUpdate()->firstOrFail();
            $this->businessVatService->applyDefault($business, $plan);
            return $subscription->fresh();
        });

        if (! $subscription) return;
        // Missing authorization is represented by the provisioner's manual review state.
        try { $this->provisioningService->provision($subscription); } catch (Throwable) { /* Never un-capture a valid payment. */ }
    }

    private function toMinorUnits(string $amount): int
    {
        $normalized = number_format((float) $amount, 2, '.', '');
        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '00');
        return ((int) $whole * 100) + (int) substr(str_pad($fraction, 2, '0'), 0, 2);
    }
}

<?php

namespace App\Domains\Payment\Services;

use App\Domains\Payment\Contracts\PaymentGateway;
use App\Domains\Subscription\Models\Subscription;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class PaystackSubscriptionProvisioningService
{
    private const MAX_LOOKUP_PAGES = 10;

    public function __construct(private PaymentGateway $paymentGateway)
    {
    }

    /**
     * Safely establish the Paystack recurring subscription for a locally
     * persisted subscription. Only a never-attempted `pending` record may
     * issue the non-idempotent create request.
     */
    public function provision(Subscription $subscription): Subscription
    {
        $lock = Cache::lock('paystack-subscription-provisioning:' . $subscription->getKey(), 300);

        return $lock->block(10, function () use ($subscription): Subscription {
            $subscription = Subscription::query()->findOrFail($subscription->getKey());

            if ($subscription->provisioning_status === 'provisioned'
                && filled($subscription->provider_subscription_code)) {
                return $subscription;
            }

            if (filled($subscription->provider_subscription_code)) {
                return $this->markManualReview(
                    $subscription,
                    'A provider subscription code exists but provisioning is not marked complete.'
                );
            }

            if ($subscription->provisioning_status === 'manual_review') {
                throw new RuntimeException('Subscription provisioning requires manual review.');
            }

            if (! in_array($subscription->provisioning_status, ['pending', 'creating', 'reconciliation_required'], true)) {
                throw new RuntimeException('This subscription is not eligible for Paystack provisioning.');
            }

            if ($subscription->provider !== 'paystack'
                || blank($subscription->provider_customer_code)
                || blank($subscription->provider_authorization_code)) {
                return $this->markManualReview(
                    $subscription,
                    'Required Paystack customer or authorization details are missing.'
                );
            }

            $plan = $subscription->plan;
            if (! $plan || blank($plan->paystack_plan_code)) {
                return $this->markManualReview($subscription, 'The local subscription plan has no Paystack plan code.');
            }

            // Provider reads happen outside database transactions and row locks.
            $planResult = $this->paymentGateway->fetchPlan($plan->paystack_plan_code);
            $providerPlan = $planResult['data'] ?? null;
            if (! ($planResult['success'] ?? false)
                || ! is_array($providerPlan)
                || ($providerPlan['plan_code'] ?? null) !== $plan->paystack_plan_code
                || ! isset($providerPlan['id'])
                || filter_var($providerPlan['id'], FILTER_VALIDATE_INT) === false
                || (int) $providerPlan['id'] < 1
                || ! isset($providerPlan['amount'])
                || (int) $providerPlan['amount'] !== $this->toMinorUnits((string) $plan->price)
                || strtoupper((string) ($providerPlan['currency'] ?? '')) !== strtoupper((string) $plan->currency)
                || ($providerPlan['interval'] ?? null) !== $this->paystackInterval((string) $plan->billing_interval)) {
                return $this->markManualReview($subscription, 'Paystack plan identity could not be verified.');
            }

            $matches = $this->findExactProviderMatches($subscription, (int) $providerPlan['id'], $plan->paystack_plan_code);
            if (count($matches) > 1) {
                return $this->markManualReview($subscription, 'Multiple matching Paystack subscriptions were found.');
            }

            if (count($matches) === 1) {
                return $this->persistProvisioned($subscription, $matches[0]);
            }

            // A prior create attempt may have reached Paystack even if our process
            // died before receiving or persisting its response. Never POST again.
            if ($subscription->provisioning_status !== 'pending') {
                return $this->markReconciliationRequired(
                    $subscription,
                    'No matching provider subscription was found after a previous creation attempt; automatic recreation is blocked.'
                );
            }

            // Commit the durable attempt marker before making the non-idempotent POST.
            $mayCreate = DB::transaction(function () use ($subscription): bool {
                $locked = Subscription::query()->lockForUpdate()->findOrFail($subscription->getKey());
                if ($locked->provisioning_status !== 'pending' || filled($locked->provider_subscription_code)) {
                    return false;
                }

                $locked->forceFill([
                    'provisioning_status' => 'creating',
                    'provisioning_attempted_at' => now(),
                    'provisioning_error' => null,
                ])->save();

                return true;
            });

            if (! $mayCreate) {
                return $subscription->fresh();
            }

            try {
                $created = $this->paymentGateway->createSubscription([
                    'customer_code' => $subscription->provider_customer_code,
                    'plan_code' => $plan->paystack_plan_code,
                    'authorization_code' => $subscription->provider_authorization_code,
                ]);
            } catch (Throwable $exception) {
                $this->markReconciliationRequired(
                    $subscription->fresh(),
                    'Paystack creation outcome is unknown (' . class_basename($exception) . ').'
                );

                throw new RuntimeException(
                    'Paystack subscription creation outcome is uncertain; reconciliation is required before any retry.',
                    previous: $exception
                );
            }

            $code = $created['subscription_code'] ?? null;
            $returnedCustomer = $created['customer_code'] ?? null;
            if (! ($created['success'] ?? false)
                || ! is_string($code)
                || trim($code) === ''
                || ($returnedCustomer !== null && $returnedCustomer !== $subscription->provider_customer_code)) {
                return $this->markReconciliationRequired(
                    $subscription->fresh(),
                    'Paystack creation returned an incomplete or inconsistent response; automatic recreation is blocked.'
                );
            }

            return $this->persistProvisioned($subscription->fresh(), [
                'subscription_code' => $code,
                'customer_code' => $returnedCustomer ?? $subscription->provider_customer_code,
                'email_token' => $created['email_token'] ?? null,
            ]);
        });
    }

    private function paystackInterval(string $interval): ?string
    {
        return match ($interval) {
            'yearly' => 'annually',
            'monthly', 'weekly', 'daily', 'hourly', 'quarterly', 'biannually' => $interval,
            default => null,
        };
    }

    private function toMinorUnits(string $amount): int
    {
        $normalized = number_format((float) $amount, 2, '.', '');
        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '00');
        return ((int) $whole * 100) + (int) substr(str_pad($fraction, 2, '0'), 0, 2);
    }

    /** @return list<array{subscription_code: string, customer_code: string, email_token: ?string}> */
    private function findExactProviderMatches(Subscription $subscription, int $providerPlanId, string $planCode): array
    {
        $matches = [];
        $pageCount = null;

        for ($page = 1; $page <= self::MAX_LOOKUP_PAGES; $page++) {
            $result = $this->paymentGateway->listSubscriptions([
                'perPage' => 100,
                'page' => $page,
                'plan' => $providerPlanId,
            ]);

            $data = $result['data'] ?? null;
            $meta = $result['meta'] ?? null;
            if (! ($result['success'] ?? false)
                || ! is_array($data)
                || ! is_array($meta)
                || ! isset($meta['pageCount'])
                || filter_var($meta['pageCount'], FILTER_VALIDATE_INT) === false
                || (int) $meta['pageCount'] < 0) {
                $this->markManualReview($subscription, 'Paystack subscription listing returned incomplete pagination data.');
                throw new RuntimeException('Paystack subscription reconciliation could not establish a complete search.');
            }

            $reportedPageCount = (int) $meta['pageCount'];
            if ($reportedPageCount === 0) {
                if ($data !== []) {
                    $this->markManualReview($subscription, 'Paystack returned subscription records with a zero page count.');
                    throw new RuntimeException('Paystack subscription pagination metadata is inconsistent.');
                }
                return [];
            }
            if ($reportedPageCount > self::MAX_LOOKUP_PAGES) {
                $this->markManualReview($subscription, 'Paystack subscription search exceeded the safe pagination limit.');
                throw new RuntimeException('Paystack subscription search exceeded the safe pagination limit.');
            }
            if ($pageCount !== null && $pageCount !== $reportedPageCount) {
                $this->markManualReview($subscription, 'Paystack pagination changed during subscription reconciliation.');
                throw new RuntimeException('Paystack subscription pagination changed during reconciliation.');
            }
            $pageCount = $reportedPageCount;

            foreach ($data as $candidate) {
                if (! is_array($candidate)
                    || data_get($candidate, 'customer.customer_code') !== $subscription->provider_customer_code
                    || data_get($candidate, 'authorization.authorization_code') !== $subscription->provider_authorization_code
                    || data_get($candidate, 'plan.plan_code') !== $planCode) {
                    continue;
                }

                if ($subscription->provider_customer_id !== null
                    && (int) data_get($candidate, 'customer.id', 0) !== (int) $subscription->provider_customer_id) {
                    continue;
                }

                $identifier = $candidate['subscription_code'] ?? $candidate['id'] ?? null;
                if (! is_string($identifier) && ! is_int($identifier)) {
                    $this->markManualReview($subscription, 'A possible matching Paystack subscription had no usable identifier.');
                    throw new RuntimeException('A possible Paystack subscription could not be individually identified.');
                }

                // Never associate a list result without verifying the individual resource.
                try {
                    $detailResult = $this->paymentGateway->fetchSubscription((string) $identifier);
                } catch (Throwable $exception) {
                    $this->markManualReview($subscription, 'A possible matching Paystack subscription could not be individually verified.');
                    throw new RuntimeException('A possible Paystack subscription could not be individually verified; creation is blocked.', previous: $exception);
                }
                $detail = $detailResult['data'] ?? null;
                if (! ($detailResult['success'] ?? false) || ! is_array($detail)) {
                    $this->markManualReview($subscription, 'A possible matching Paystack subscription returned invalid details.');
                    throw new RuntimeException('A possible Paystack subscription returned invalid details; creation is blocked.');
                }

                $detailCode = $detail['subscription_code'] ?? null;
                $detailCustomer = data_get($detail, 'customer.customer_code');
                $detailAuthorization = data_get($detail, 'authorization.authorization_code');
                $detailPlanCode = data_get($detail, 'plan.plan_code');
                $detailCustomerId = data_get($detail, 'customer.id');

                if (! is_string($detailCode)
                    || $detailCode === ''
                    || $detailCustomer !== $subscription->provider_customer_code
                    || $detailAuthorization !== $subscription->provider_authorization_code
                    || $detailPlanCode !== $planCode
                    || ($subscription->provider_customer_id !== null
                        && (int) $detailCustomerId !== (int) $subscription->provider_customer_id)) {
                    continue;
                }

                $matches[$detailCode] = [
                    'subscription_code' => $detailCode,
                    'customer_code' => $detailCustomer,
                    'email_token' => $detail['email_token'] ?? null,
                ];
            }

            if ($page >= $pageCount) {
                break;
            }
        }

        return array_values($matches);
    }

    private function persistProvisioned(Subscription $subscription, array $providerData): Subscription
    {
        return DB::transaction(function () use ($subscription, $providerData): Subscription {
            $locked = Subscription::query()->lockForUpdate()->findOrFail($subscription->getKey());
            $newCode = $providerData['subscription_code'] ?? null;

            if (filled($locked->provider_subscription_code)
                && $locked->provider_subscription_code !== $newCode) {
                $locked->forceFill([
                    'provisioning_status' => 'manual_review',
                    'provisioning_error' => 'A different provider subscription code is already stored locally.',
                ])->save();
                return $locked->refresh();
            }

            $locked->forceFill([
                'provider_customer_code' => $providerData['customer_code'] ?? $locked->provider_customer_code,
                'provider_subscription_code' => $newCode,
                'provider_email_token' => $providerData['email_token'] ?? $locked->provider_email_token,
                'provisioning_status' => 'provisioned',
                'provisioning_error' => null,
            ])->save();

            return $locked->refresh();
        });
    }

    private function markReconciliationRequired(Subscription $subscription, string $reason): Subscription
    {
        return $this->persistState($subscription, 'reconciliation_required', $reason);
    }

    private function markManualReview(Subscription $subscription, string $reason): Subscription
    {
        return $this->persistState($subscription, 'manual_review', $reason);
    }

    private function persistState(Subscription $subscription, string $status, string $reason): Subscription
    {
        return DB::transaction(function () use ($subscription, $status, $reason): Subscription {
            $locked = Subscription::query()->lockForUpdate()->findOrFail($subscription->getKey());
            if ($locked->provisioning_status !== 'provisioned') {
                $locked->forceFill([
                    'provisioning_status' => $status,
                    'provisioning_error' => mb_substr($reason, 0, 1000),
                ])->save();
            }
            return $locked->refresh();
        });
    }
}

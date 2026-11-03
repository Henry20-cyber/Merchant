<?php

namespace App\Domains\Subscription\Services;

use App\Domains\Payment\Contracts\PaymentGateway;
use App\Domains\Organization\Models\BusinessUser;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use App\Domains\Subscription\Models\Subscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubscriptionBillingService
{
    public function __construct(
        private PaymentGateway $paymentGateway,
    ) {
    }

    /**
     * Stop the next automatic renewal while preserving access until
     * the current billing period ends.
     */
    public function disableRenewal(
        Subscription $subscription,
        User $actor,
    ): Subscription {
        return $this->runProviderRenewalChange($subscription, $actor, false);
    }

    /**
     * Resume automatic renewal before the current billing period ends.
     */
    public function enableRenewal(
        Subscription $subscription,
        User $actor,
    ): Subscription {
        return $this->runProviderRenewalChange($subscription, $actor, true);
    }

    /**
     * Keep provider network I/O outside database transactions and serialize
     * changes with an atomic cache lock. A provider failure leaves local state
     * unchanged; ambiguous outcomes must be checked with Paystack before retry.
     */
    private function runProviderRenewalChange(
        Subscription $subscription,
        User $actor,
        bool $enable,
    ): Subscription {
        $lock = \Illuminate\Support\Facades\Cache::lock(
            'subscription-renewal:' . $subscription->getKey(),
            60,
        );

        if (! $lock->get()) {
            throw ValidationException::withMessages([
                'subscription' => 'A recurring-billing change is already in progress. Please try again shortly.',
            ]);
        }

        try {
            $current = DB::transaction(function () use ($subscription, $actor, $enable) {
                $locked = Subscription::query()
                    ->with('plan')
                    ->lockForUpdate()
                    ->findOrFail($subscription->id);

                $this->authorizeBillingManager($actor, $locked);
                $this->ensureManageable($locked);

                if ($enable && $locked->current_period_end !== null
                    && $locked->current_period_end->isPast()) {
                    throw ValidationException::withMessages([
                        'subscription' => 'The current billing period has ended. Start a new subscription instead.',
                    ]);
                }

                $this->ensureProviderSubscription($locked);

                if ((bool) $locked->auto_renew === $enable) {
                    return null;
                }

                return [
                    'id' => $locked->id,
                    'provider_subscription_code' => $locked->provider_subscription_code,
                    'provider_email_token' => $locked->provider_email_token,
                    'auto_renew' => (bool) $locked->auto_renew,
                    'status' => $locked->status,
                    'current_period_end' => $locked->current_period_end?->toISOString(),
                ];
            });

            if ($current === null) return $subscription->fresh(['plan']);

            // Paystack network request: transactionLevel() must be zero here.
            $result = $enable
                ? $this->paymentGateway->enableSubscription(
                    $current['provider_subscription_code'],
                    $current['provider_email_token'],
                )
                : $this->paymentGateway->disableSubscription(
                    $current['provider_subscription_code'],
                    $current['provider_email_token'],
                );

            if (! ($result['success'] ?? false)) {
                throw ValidationException::withMessages([
                    'subscription' => 'The payment provider did not confirm the recurring-billing change.',
                ]);
            }

            return DB::transaction(function () use ($current, $enable) {
                $locked = Subscription::query()
                    ->with('plan')
                    ->lockForUpdate()
                    ->findOrFail($current['id']);

                $stateChanged =
                    (bool) $locked->auto_renew !== $current['auto_renew']
                    || $locked->provider_subscription_code !== $current['provider_subscription_code']
                    || $locked->provider_email_token !== $current['provider_email_token']
                    || $locked->status !== $current['status']
                    || $locked->current_period_end?->toISOString() !== $current['current_period_end'];

                if ($stateChanged) {
                    throw ValidationException::withMessages([
                        'subscription' => 'The subscription changed while the provider request was processing. Refresh and verify its status before trying again.',
                    ]);
                }

                $locked->forceFill([
                    'auto_renew' => $enable,
                    'cancelled_at' => $enable ? null : now(),
                ])->save();

                return $locked->refresh()->load('plan');
            });
        } finally {
            $lock->release();
        }
    }

    /**
     * Synchronize a provider webhook indicating that the subscription
     * will not renew.
     */
    public function markNonRenewing(
        string $provider,
        string $providerSubscriptionCode
    ): ?Subscription {
        return DB::transaction(function () use (
            $provider,
            $providerSubscriptionCode,
        ) {
            $subscription = Subscription::query()
                ->where('provider', $provider)
                ->where(
                    'provider_subscription_code',
                    $providerSubscriptionCode,
                )
                ->lockForUpdate()
                ->first();

            if (! $subscription) {
                return null;
            }

            $subscription->forceFill([
                'auto_renew' => false,
                'cancelled_at' =>
                    $subscription->cancelled_at ?? now(),
            ])->save();

            return $subscription->refresh();
        });
    }

    /**
     * Synchronize a provider webhook indicating that a subscription
     * has reached its terminal disabled state.
     */
    public function markDisabled(
        string $provider,
        string $providerSubscriptionCode
    ): ?Subscription {
        return DB::transaction(function () use (
            $provider,
            $providerSubscriptionCode,
        ) {
            $subscription = Subscription::query()
                ->where('provider', $provider)
                ->where(
                    'provider_subscription_code',
                    $providerSubscriptionCode,
                )
                ->lockForUpdate()
                ->first();

            if (! $subscription) {
                return null;
            }

            $subscription->forceFill([
                'auto_renew' => false,
                'status' => 'cancelled',
                'ended_at' =>
                    $subscription->ended_at ?? now(),
            ])->save();

            return $subscription->refresh();
        });
    }


    /**
     * Authorize interactive billing changes against the subscription tenant.
     * Webhook synchronization methods intentionally remain system operations.
     */
    private function authorizeBillingManager(
        User $actor,
        Subscription $subscription
    ): void {
        $isActiveMember = BusinessUser::query()
            ->where('business_id', $subscription->business_id)
            ->where('user_id', $actor->id)
            ->where('status', 'active')
            ->lockForUpdate()
            ->first();

        if (! $isActiveMember) {
            throw new AccessDeniedHttpException(
                'You do not have access to manage billing for this business.'
            );
        }

        setPermissionsTeamId($subscription->business_id);
        $actor->unsetRelation('roles');
        $actor->unsetRelation('permissions');

        if (! $actor->can('subscription.manage')) {
            throw new AccessDeniedHttpException(
                'You do not have permission to manage this business subscription.'
            );
        }
    }

    private function ensureManageable(
        Subscription $subscription
    ): void {
        if (! $subscription->plan) {
            throw ValidationException::withMessages([
                'subscription' => 'The subscription plan could not be found.',
            ]);
        }

        if ((float) $subscription->plan->price <= 0) {
            throw ValidationException::withMessages([
                'subscription' =>
                    'Recurring billing is not applicable to the Free plan.',
            ]);
        }

        if ($subscription->status !== 'active') {
            throw ValidationException::withMessages([
                'subscription' =>
                    'Only an active subscription can have recurring billing changed.',
            ]);
        }
    }

    private function ensureProviderSubscription(
        Subscription $subscription
    ): void {
        if (
            ! $subscription->provider_subscription_code ||
            ! $subscription->provider_email_token
        ) {
            throw ValidationException::withMessages([
                'subscription' =>
                    'Recurring billing cannot be changed because the provider subscription credentials are unavailable.',
            ]);
        }
    }
}
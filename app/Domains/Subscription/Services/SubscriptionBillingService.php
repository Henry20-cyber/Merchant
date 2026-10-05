<?php

namespace App\Domains\Subscription\Services;

use App\Domains\Payment\Contracts\PaymentGateway;
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
        Subscription $subscription
    ): Subscription {
        return DB::transaction(function () use ($subscription) {
            $subscription = Subscription::query()
                ->with('plan')
                ->lockForUpdate()
                ->findOrFail($subscription->id);

            $this->ensureManageable($subscription);

            if (! $subscription->auto_renew) {
                return $subscription->refresh();
            }

            $this->ensureProviderSubscription($subscription);

            $this->paymentGateway->disableSubscription(
                $subscription->provider_subscription_code,
                $subscription->provider_email_token,
            );

            $subscription->forceFill([
                'auto_renew' => false,
                'cancelled_at' => now(),
            ])->save();

            return $subscription->refresh();
        });
    }

    /**
     * Resume automatic renewal before the current billing period ends.
     */
    public function enableRenewal(
        Subscription $subscription
    ): Subscription {
        return DB::transaction(function () use ($subscription) {
            $subscription = Subscription::query()
                ->with('plan')
                ->lockForUpdate()
                ->findOrFail($subscription->id);

            $this->ensureManageable($subscription);

            if ($subscription->auto_renew) {
                return $subscription->refresh();
            }

            $this->ensureProviderSubscription($subscription);

            if (
                $subscription->current_period_end !== null &&
                $subscription->current_period_end->isPast()
            ) {
                throw ValidationException::withMessages([
                    'subscription' =>
                        'The current billing period has ended. Start a new subscription instead.',
                ]);
            }

            $this->paymentGateway->enableSubscription(
                $subscription->provider_subscription_code,
                $subscription->provider_email_token,
            );

            $subscription->forceFill([
                'auto_renew' => true,
                'cancelled_at' => null,
            ])->save();

            return $subscription->refresh();
        });
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
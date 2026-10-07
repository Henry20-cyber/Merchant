<?php

namespace App\Domains\Subscription\Services;

use App\Domains\Customer\Models\Customer;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Models\BusinessUser;
use App\Domains\Subscription\Models\Subscription;
use Illuminate\Validation\ValidationException;

class SubscriptionLimitService
{
    /**
     * Ensure the business may create another customer.
     *
     * The caller should execute the protected operation inside
     * a database transaction. The business row is locked here so
     * concurrent resource creations for the same business cannot
     * both pass the quota check before either one commits.
     */
    public function ensureCustomerCapacity(Business $business): void
    {
        $subscription = $this->usableSubscription($business);
        $limit = $subscription->plan->customer_limit;

        if ($limit === null) {
            return;
        }

        $this->lockBusiness($business);

        $count = Customer::query()
            ->where('business_id', $business->id)
            ->count();

        if ($count >= $limit) {
            throw ValidationException::withMessages([
                'customer' => 'The customer limit for your current subscription plan has been reached. Please upgrade your plan to add more customers.',
            ]);
        }
    }

    /**
     * Ensure the business may add another active member.
     */
    public function ensureUserCapacity(Business $business): void
    {
        $subscription = $this->usableSubscription($business);
        $limit = $subscription->plan->user_limit;

        if ($limit === null) {
            return;
        }

        $this->lockBusiness($business);

        $count = BusinessUser::query()
            ->where('business_id', $business->id)
            ->where('status', 'active')
            ->count();

        if ($count >= $limit) {
            throw ValidationException::withMessages([
                'user' => 'The user limit for your current subscription plan has been reached. Please upgrade your plan to add more users.',
            ]);
        }
    }

    /**
     * Ensure the business may create another branch.
     */
    public function ensureBranchCapacity(Business $business): void
    {
        $subscription = $this->usableSubscription($business);
        $limit = $subscription->plan->branch_limit;

        if ($limit === null) {
            return;
        }

        $this->lockBusiness($business);

        $count = Branch::query()
            ->where('business_id', $business->id)
            ->count();

        if ($count >= $limit) {
            throw ValidationException::withMessages([
                'branch' => 'The branch limit for your current subscription plan has been reached. Please upgrade your plan to add more branches.',
            ]);
        }
    }

    /**
     * Return the business subscription only when it can currently
     * grant access and its plan is active.
     */
    private function usableSubscription(Business $business): Subscription
    {
        $subscription = $business
            ->subscription()
            ->with('plan')
            ->first();

        if (! $subscription) {
            throw ValidationException::withMessages([
                'subscription' => 'This business does not have a subscription.',
            ]);
        }

        $subscriptionService = app(SubscriptionService::class);

        if (! $subscriptionService->isUsable($subscription)) {
            throw ValidationException::withMessages([
                'subscription' => 'The current subscription does not allow this operation.',
            ]);
        }

        if (! $subscription->plan || ! $subscription->plan->is_active) {
            throw ValidationException::withMessages([
                'plan' => 'The subscription plan is not active.',
            ]);
        }

        return $subscription;
    }

    /**
     * Serialize resource creation for a business when enforcing a quota.
     */
    private function lockBusiness(Business $business): void
    {
        Business::query()
            ->whereKey($business->id)
            ->lockForUpdate()
            ->firstOrFail();
    }
}

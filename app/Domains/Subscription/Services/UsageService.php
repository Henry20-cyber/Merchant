<?php

namespace App\Domains\Subscription\Services;

use App\Domains\Organization\Models\Business;
use App\Domains\Subscription\Enums\UsageMetric;
use App\Domains\Subscription\Models\UsageRecord;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

class UsageService
{
  /**
   * Determine whether the business can create another
   * sales transaction.
   */
  public function canCreateTransaction(
    Business $business
  ): bool {
    $subscription = $business
      ->subscription()
      ->with('plan')
      ->first();

    if (! $subscription) {
      return false;
    }

    /*
     * The subscription itself must be usable.
     */
    if (! app(SubscriptionService::class)->isUsable($subscription)) {
      return false;
    }

    /*
     * The subscription's plan must also be active.
     *
     * An active subscription attached to a deactivated
     * plan must not be allowed to consume usage.
     */
    if (! $subscription->plan || ! $subscription->plan->is_active) {
      return false;
    }

    $plan = $subscription->plan;

    $dailyLimit = $plan->transaction_daily_limit;
    $monthlyLimit = $plan->transaction_monthly_limit;

    /*
     * NULL on both limits means unlimited.
     */
    if (
      $dailyLimit === null &&
      $monthlyLimit === null
    ) {
      return true;
    }

    $now = now();

    if ($dailyLimit !== null) {
      $dailyUsage = $this->getDailyTransactionUsage(
        $business,
        $now
      );

      if ($dailyUsage >= $dailyLimit) {
        return false;
      }
    }

    if ($monthlyLimit !== null) {
      $monthlyUsage = $this->getMonthlyTransactionUsage(
        $business,
        $now
      );

      if ($monthlyUsage >= $monthlyLimit) {
        return false;
      }
    }

    return true;
  }

  /**
   * Consume one sales transaction.
   *
   * This method must be called inside the same database
   * transaction as the operation being protected.
   */
  public function consumeTransaction(
    Business $business
  ): void {
    $subscription = $business
      ->subscription()
      ->with('plan')
      ->first();

    if (! $subscription) {
      throw ValidationException::withMessages([
        'subscription' => 'This business does not have a subscription.',
      ]);
    }

    if (! app(SubscriptionService::class)->isUsable($subscription)) {
      throw ValidationException::withMessages([
        'subscription' => 'The current subscription does not allow transactions.',
      ]);
    }

    /*
     * The plan itself must remain active.
     */
    if (! $subscription->plan || ! $subscription->plan->is_active) {
      throw ValidationException::withMessages([
        'plan' => 'The subscription plan is not active.',
      ]);
    }

    $plan = $subscription->plan;

    // ... rest of method

    $dailyLimit = $plan->transaction_daily_limit;
    $monthlyLimit = $plan->transaction_monthly_limit;

    /*
         * Unlimited transactions.
         */
    if (
      $dailyLimit === null &&
      $monthlyLimit === null
    ) {
      return;
    }

    $now = now();

    /*
         * Daily period.
         */
    $dailyStart = $now->copy()->startOfDay();
    $dailyEnd = $dailyStart->copy()->addDay();

    /*
         * Monthly period.
         */
    $monthlyStart = $now->copy()->startOfMonth();
    $monthlyEnd = $monthlyStart->copy()->addMonth();

    /*
         * Lock the usage records while checking and
         * incrementing them.
         *
         * This prevents concurrent requests from
         * bypassing the limit.
         */
    if ($dailyLimit !== null) {
      $dailyRecord = UsageRecord::query()
        ->where('business_id', $business->id)
        ->where(
          'metric',
          UsageMetric::SALES_TRANSACTIONS->value
        )
        ->where('period_start', $dailyStart)
        ->where('period_end', $dailyEnd)
        ->lockForUpdate()
        ->first();

      if (! $dailyRecord) {
        $dailyRecord = UsageRecord::create([
          'business_id' => $business->id,
          'metric' => UsageMetric::SALES_TRANSACTIONS->value,
          'quantity' => 0,
          'period_start' => $dailyStart,
          'period_end' => $dailyEnd,
        ]);
      }

      if ($dailyRecord->quantity >= $dailyLimit) {
        throw ValidationException::withMessages([
          'usage' => 'The daily transaction limit has been reached. Please upgrade your plan to continue.',
        ]);
      }
    }

    /*
         * Lock/check monthly usage.
         */
    if ($monthlyLimit !== null) {
      $monthlyRecord = UsageRecord::query()
        ->where('business_id', $business->id)
        ->where(
          'metric',
          UsageMetric::SALES_TRANSACTIONS->value
        )
        ->where('period_start', $monthlyStart)
        ->where('period_end', $monthlyEnd)
        ->lockForUpdate()
        ->first();

      if (! $monthlyRecord) {
        $monthlyRecord = UsageRecord::create([
          'business_id' => $business->id,
          'metric' => UsageMetric::SALES_TRANSACTIONS->value,
          'quantity' => 0,
          'period_start' => $monthlyStart,
          'period_end' => $monthlyEnd,
        ]);
      }

      if ($monthlyRecord->quantity >= $monthlyLimit) {
        throw ValidationException::withMessages([
          'usage' => 'The monthly transaction limit has been reached. Please upgrade your plan to continue.',
        ]);
      }
    }

    /*
         * Increment both buckets.
         */
    if ($dailyLimit !== null) {
      $dailyRecord->increment('quantity');
    }

    if ($monthlyLimit !== null) {
      $monthlyRecord->increment('quantity');
    }
  }

  /**
   * Get today's transaction usage.
   */
  public function getDailyTransactionUsage(
    Business $business,
    ?CarbonInterface $at = null
  ): int {
    $at ??= now();

    $start = $at->copy()->startOfDay();
    $end = $start->copy()->addDay();

    return (int) UsageRecord::query()
      ->where('business_id', $business->id)
      ->where(
        'metric',
        UsageMetric::SALES_TRANSACTIONS->value
      )
      ->where('period_start', $start)
      ->where('period_end', $end)
      ->value('quantity');
  }

  /**
   * Get this month's transaction usage.
   */
  public function getMonthlyTransactionUsage(
    Business $business,
    ?CarbonInterface $at = null
  ): int {
    $at ??= now();

    $start = $at->copy()->startOfMonth();
    $end = $start->copy()->addMonth();

    return (int) UsageRecord::query()
      ->where('business_id', $business->id)
      ->where(
        'metric',
        UsageMetric::SALES_TRANSACTIONS->value
      )
      ->where('period_start', $start)
      ->where('period_end', $end)
      ->value('quantity');
  }
}

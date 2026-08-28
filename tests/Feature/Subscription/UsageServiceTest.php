<?php

namespace Tests\Feature\Subscription;

use App\Domains\Organization\Models\Business;
use App\Domains\Subscription\Enums\UsageMetric;
use App\Domains\Subscription\Models\Subscription;
use App\Domains\Subscription\Models\SubscriptionPlan;
use App\Domains\Subscription\Models\UsageRecord;
use App\Domains\Subscription\Services\UsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class UsageServiceTest extends TestCase
{
    use RefreshDatabase;

    private UsageService $usageService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->usageService = app(UsageService::class);
    }

    /**
     * Create a business with an active subscription.
     */
    private function createBusinessWithPlan(
    int|null $dailyLimit = 10,
    int|null $monthlyLimit = 30,
): array {
    $business = Business::factory()->create();

    $plan = SubscriptionPlan::factory()->create([
        'is_active' => true,
        'transaction_daily_limit' => $dailyLimit,
        'transaction_monthly_limit' => $monthlyLimit,
    ]);

    $subscription = Subscription::create([
        'business_id' => $business->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'starts_at' => now()->subDay(),
        'current_period_start' => now()->subDay(),
        'current_period_end' => now()->addMonth(),
        'grace_period_ends_at' => null,
        'restricted_at' => null,
        'cancelled_at' => null,
        'ended_at' => null,
    ]);

    /*
     * Force both models to represent the actual
     * database state.
     */
    $subscription = $subscription->fresh();

    $business = $business->fresh();

    return [
        $business,
        $plan->fresh(),
        $subscription,
    ];
}

    public function test_daily_usage_starts_at_zero(): void
    {
        [$business] = $this->createBusinessWithPlan();

        expect(
            $this->usageService->getDailyTransactionUsage($business)
        )->toBe(0);
    }

    public function test_monthly_usage_starts_at_zero(): void
    {
        [$business] = $this->createBusinessWithPlan();

        expect(
            $this->usageService->getMonthlyTransactionUsage($business)
        )->toBe(0);
    }

    public function test_transaction_increments_daily_usage(): void
    {
        [$business] = $this->createBusinessWithPlan();

        $this->usageService->consumeTransaction($business);

        expect(
            $this->usageService->getDailyTransactionUsage($business)
        )->toBe(1);
    }

    public function test_transaction_increments_monthly_usage(): void
    {
        [$business] = $this->createBusinessWithPlan();

        $this->usageService->consumeTransaction($business);

        expect(
            $this->usageService->getMonthlyTransactionUsage($business)
        )->toBe(1);
    }

    public function test_transaction_increments_both_usage_buckets(): void
    {
        [$business] = $this->createBusinessWithPlan();

        $this->usageService->consumeTransaction($business);
        $this->usageService->consumeTransaction($business);
        $this->usageService->consumeTransaction($business);

        expect(
            $this->usageService->getDailyTransactionUsage($business)
        )->toBe(3);

        expect(
            $this->usageService->getMonthlyTransactionUsage($business)
        )->toBe(3);
    }

    public function test_daily_limit_blocks_transaction(): void
    {
        [$business] = $this->createBusinessWithPlan(
            dailyLimit: 2,
            monthlyLimit: 30,
        );

        $this->usageService->consumeTransaction($business);
        $this->usageService->consumeTransaction($business);

        expect(
            $this->usageService->canCreateTransaction($business)
        )->toBeFalse();

        expect(
            fn() =>
            $this->usageService->consumeTransaction($business)
        )->toThrow(ValidationException::class);

        expect(
            $this->usageService->getDailyTransactionUsage($business)
        )->toBe(2);

        expect(
            $this->usageService->getMonthlyTransactionUsage($business)
        )->toBe(2);
    }

    public function test_monthly_limit_blocks_transaction(): void
    {
        [$business] = $this->createBusinessWithPlan(
            dailyLimit: 100,
            monthlyLimit: 2,
        );

        $this->usageService->consumeTransaction($business);
        $this->usageService->consumeTransaction($business);

        expect(
            $this->usageService->canCreateTransaction($business)
        )->toBeFalse();

        expect(
            fn() =>
            $this->usageService->consumeTransaction($business)
        )->toThrow(ValidationException::class);

        expect(
            $this->usageService->getDailyTransactionUsage($business)
        )->toBe(2);

        expect(
            $this->usageService->getMonthlyTransactionUsage($business)
        )->toBe(2);
    }

    public function test_both_daily_and_monthly_limits_are_enforced(): void
    {
        [$business] = $this->createBusinessWithPlan(
            dailyLimit: 2,
            monthlyLimit: 3,
        );

        $this->usageService->consumeTransaction($business);
        $this->usageService->consumeTransaction($business);

        expect(
            $this->usageService->canCreateTransaction($business)
        )->toBeFalse();

        expect(
            fn() =>
            $this->usageService->consumeTransaction($business)
        )->toThrow(ValidationException::class);
    }

    public function test_monthly_limit_can_block_even_when_daily_limit_has_capacity(): void
    {
        [$business] = $this->createBusinessWithPlan(
            dailyLimit: 10,
            monthlyLimit: 2,
        );

        $monthlyStart = now()->startOfMonth();
        $monthlyEnd = $monthlyStart->copy()->addMonth();

        UsageRecord::create([
            'business_id' => $business->id,
            'metric' => UsageMetric::SALES_TRANSACTIONS->value,
            'quantity' => 2,
            'period_start' => $monthlyStart,
            'period_end' => $monthlyEnd,
        ]);

        expect(
            $this->usageService->getDailyTransactionUsage($business)
        )->toBe(0);

        expect(
            $this->usageService->getMonthlyTransactionUsage($business)
        )->toBe(2);

        expect(
            $this->usageService->canCreateTransaction($business)
        )->toBeFalse();
    }

    public function test_unlimited_plan_bypasses_transaction_limits(): void
    {
        [$business] = $this->createBusinessWithPlan(
            dailyLimit: null,
            monthlyLimit: null,
        );

        expect(
            $this->usageService->canCreateTransaction($business)
        )->toBeTrue();

        for ($i = 0; $i < 20; $i++) {
            $this->usageService->consumeTransaction($business);
        }

        expect(
            $this->usageService->getDailyTransactionUsage($business)
        )->toBe(0);

        expect(
            $this->usageService->getMonthlyTransactionUsage($business)
        )->toBe(0);
    }

    public function test_missing_subscription_cannot_consume_transactions(): void
    {
        $business = Business::factory()->create();

        expect(
            $this->usageService->canCreateTransaction($business)
        )->toBeFalse();

        expect(
            fn() =>
            $this->usageService->consumeTransaction($business)
        )->toThrow(ValidationException::class);
    }

    public function test_inactive_plan_cannot_consume_transactions(): void
    {
        [$business, $plan] = $this->createBusinessWithPlan();

        $plan->update([
            'is_active' => false,
        ]);

        expect(
            $this->usageService->canCreateTransaction($business)
        )->toBeFalse();

        expect(
            fn() =>
            $this->usageService->consumeTransaction($business)
        )->toThrow(ValidationException::class);
    }

   public function test_expired_subscription_cannot_consume_transactions(): void
{
    [$business, , $subscription] = $this->createBusinessWithPlan();

    $subscription->forceFill([
        'current_period_end' => now()->subMinute(),
    ])->save();

    $subscription = $subscription->fresh();
    $business = $business->fresh();

    expect($subscription->current_period_end)->not->toBeNull();

    expect(
        $subscription->current_period_end->isPast()
    )->toBeTrue();

    expect(
        $business->subscription()->first()->id
    )->toBe($subscription->id);

    expect(
        $business->subscription()->first()->current_period_end->isPast()
    )->toBeTrue();

    expect(
        $this->usageService->canCreateTransaction($business)
    )->toBeFalse();

    expect(
        fn () => $this->usageService->consumeTransaction($business)
    )->toThrow(ValidationException::class);
}

    public function test_usage_periods_are_separate(): void
    {
        [$business] = $this->createBusinessWithPlan();

        $yesterday = now()->subDay();

        UsageRecord::create([
            'business_id' => $business->id,
            'metric' => UsageMetric::SALES_TRANSACTIONS->value,
            'quantity' => 5,
            'period_start' => $yesterday->copy()->startOfDay(),
            'period_end' => $yesterday->copy()->startOfDay()->addDay(),
        ]);

        expect(
            $this->usageService->getDailyTransactionUsage($business)
        )->toBe(0);

        $this->usageService->consumeTransaction($business);

        expect(
            $this->usageService->getDailyTransactionUsage($business)
        )->toBe(1);
    }

    public function test_usage_record_is_created_once_per_period(): void
    {
        [$business] = $this->createBusinessWithPlan();

        $this->usageService->consumeTransaction($business);
        $this->usageService->consumeTransaction($business);

        expect(
            UsageRecord::where('business_id', $business->id)
                ->where(
                    'metric',
                    UsageMetric::SALES_TRANSACTIONS->value
                )
                ->count()
        )->toBe(2);
    }
}

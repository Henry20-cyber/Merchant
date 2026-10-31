<?php

namespace Tests\Unit\Domains\Subscription;

use App\Domains\Subscription\Services\SubscriptionPeriodCalculator;
use Carbon\Carbon;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class SubscriptionPeriodCalculatorTest extends TestCase
{
    private SubscriptionPeriodCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new SubscriptionPeriodCalculator();
    }

    public function test_monthly_period_preserves_normal_anniversary(): void
    {
        $start = Carbon::parse('2026-03-15 10:30:00');

        $end = $this->calculator->endFor($start, 'monthly');

        $this->assertSame('2026-04-15 10:30:00', $end->toDateTimeString());
    }

    public function test_january_31_monthly_period_clamps_to_february_end(): void
    {
        $start = Carbon::parse('2025-01-31 10:30:00');

        $end = $this->calculator->endFor($start, 'monthly');

        $this->assertSame('2025-02-28 10:30:00', $end->toDateTimeString());
    }

    public function test_january_31_monthly_period_uses_february_29_in_leap_year(): void
    {
        $start = Carbon::parse('2024-01-31 10:30:00');

        $end = $this->calculator->endFor($start, 'monthly');

        $this->assertSame('2024-02-29 10:30:00', $end->toDateTimeString());
    }

    public function test_march_31_monthly_period_clamps_to_april_30(): void
    {
        $start = Carbon::parse('2026-03-31 10:30:00');

        $end = $this->calculator->endFor($start, 'monthly');

        $this->assertSame('2026-04-30 10:30:00', $end->toDateTimeString());
    }

    public function test_yearly_period_preserves_normal_anniversary(): void
    {
        $start = Carbon::parse('2025-06-15 10:30:00');

        $end = $this->calculator->endFor($start, 'yearly');

        $this->assertSame('2026-06-15 10:30:00', $end->toDateTimeString());
    }

    public function test_february_29_yearly_period_clamps_in_non_leap_year(): void
    {
        $start = Carbon::parse('2024-02-29 10:30:00');

        $end = $this->calculator->endFor($start, 'yearly');

        $this->assertSame('2025-02-28 10:30:00', $end->toDateTimeString());
    }

    public function test_unsupported_billing_interval_is_rejected(): void
    {
        $start = Carbon::parse('2026-01-15 10:30:00');

        $this->expectException(InvalidArgumentException::class);

        $this->calculator->endFor($start, 'weekly');
    }
}

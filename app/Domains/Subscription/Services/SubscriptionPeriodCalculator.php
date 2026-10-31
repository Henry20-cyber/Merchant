<?php

namespace App\Domains\Subscription\Services;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use DateTime;

class SubscriptionPeriodCalculator
{
    /**
     * Calculate an exclusive billing-period end without date overflow.
     *
     * Monthly: 31 January -> 28 February (29 in a leap year).
     * Yearly: 29 February -> 28 February in a non-leap year.
     */
    public function endFor(
        CarbonInterface $periodStart,
        string $billingInterval,
    ): Carbon {
        $end = Carbon::instance(
            DateTime::createFromInterface($periodStart)
        );

        return match ($billingInterval) {
            'monthly' => $end->addMonthNoOverflow(),
            'yearly' => $end->addYearNoOverflow(),
            default => throw new \InvalidArgumentException(
                "Unsupported subscription billing interval: {$billingInterval}"
            ),
        };
    }
}

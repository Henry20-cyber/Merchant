<?php

namespace App\Domains\Organization\Services;

use App\Domains\Organization\Models\Business;
use App\Domains\Subscription\Models\SubscriptionPlan;

class BusinessVatService
{
    /**
     * Determine the default VAT setting for a subscription plan.
     *
     * Small plans (Free/Starter) are VAT-exempt by default.
     * Medium and Large plans are VAT-enabled by default.
     */
    public function defaultEnabledForPlan(
        SubscriptionPlan $plan
    ): bool {
        return str_starts_with($plan->slug, 'medium')
            || str_starts_with($plan->slug, 'large');
    }

    /**
     * Apply the plan's default VAT setting to a business.
     *
     * The merchant can override this later from Settings.
     */
    public function applyDefault(
        Business $business,
        SubscriptionPlan $plan
    ): Business {
        $business->forceFill([
            'vat_enabled' => $this->defaultEnabledForPlan($plan),
        ])->save();

        return $business->refresh();
    }
}

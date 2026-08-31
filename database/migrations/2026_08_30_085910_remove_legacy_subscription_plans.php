<?php

use App\Domains\Subscription\Models\SubscriptionPlan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            /*
             * These plans were created by the old subscription
             * catalogue and are no longer part of MerchantOS's
             * canonical plan catalogue.
             *
             * Before deleting them, make absolutely certain that
             * no subscriptions still reference them.
             */
            $legacyPlans = SubscriptionPlan::query()
                ->whereIn('slug', [
                    'low',
                    'medium',
                    'large',
                ])
                ->orWhere('slug', 'like', 'medium-monthly-%')
                ->get();

            foreach ($legacyPlans as $plan) {
                if ($plan->subscriptions()->exists()) {
                    throw new RuntimeException(
                        "Cannot remove subscription plan [{$plan->slug}] " .
                        "because subscriptions still reference it."
                    );
                }

                $plan->delete();
            }
        });
    }

    public function down(): void
    {
        /*
         * These were obsolete/test records rather than part of
         * the canonical catalogue.
         *
         * We intentionally do not recreate them during rollback.
         */
    }
};
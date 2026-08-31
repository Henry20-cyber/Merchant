<?php

namespace App\Console\Commands;

use App\Domains\Subscription\Models\Subscription;
use App\Domains\Subscription\Models\SubscriptionPlan;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class NormalizeSubscriptionPlans extends Command
{
    protected $signature = 'subscriptions:normalize-plans';

    protected $description = 'Normalize legacy and factory-created subscription plans';

    public function handle(): int
    {
        $this->info('Normalizing subscription plans...');

        $canonicalPlans = [
            'free',
            'low-monthly',
            'low-yearly',
            'medium-monthly',
            'medium-yearly',
            'large-monthly',
            'large-yearly',
        ];

        /*
         * Make sure the canonical catalogue exists.
         */
        foreach ($canonicalPlans as $slug) {
            if (! SubscriptionPlan::query()
                ->where('slug', $slug)
                ->exists()) {
                $this->error(
                    "Canonical plan [{$slug}] does not exist."
                );

                return self::FAILURE;
            }
        }

        $mediumMonthly = SubscriptionPlan::query()
            ->where('slug', 'medium-monthly')
            ->firstOrFail();

        DB::transaction(function () use ($mediumMonthly) {
            /*
             * Migrate subscriptions using randomized
             * Medium Monthly factory plans.
             */
            $subscriptions = Subscription::query()
                ->whereHas('plan', function ($query) {
                    $query->where(
                        'name',
                        'Medium Monthly'
                    )
                    ->where(
                        'billing_interval',
                        'monthly'
                    )
                    ->where(
                        'price',
                        '10000.00'
                    )
                    ->where(
                        'slug',
                        'like',
                        'medium-monthly-%'
                    );
                })
                ->lockForUpdate()
                ->get();

            foreach ($subscriptions as $subscription) {
                $oldPlan = $subscription->plan;

                $subscription->forceFill([
                    'plan_id' => $mediumMonthly->id,
                ])->save();

                $this->line(
                    "Subscription {$subscription->id}: " .
                    "{$oldPlan->slug} → medium-monthly"
                );
            }

            /*
             * Legacy plans such as:
             *
             * low
             * medium
             * large
             *
             * are not deleted here.
             *
             * They may still have historical references.
             */
        });

        $this->info(
            'Subscription plan normalization completed.'
        );

        return self::SUCCESS;
    }
}
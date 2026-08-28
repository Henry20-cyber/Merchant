<?php

namespace App\Console\Commands;

use App\Domains\Subscription\Models\Subscription;
use App\Domains\Subscription\Services\SubscriptionLifecycleService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('subscriptions:process')]
#[Description('Process MerchantOS subscription lifecycle transitions')]
class ProcessSubscriptions extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(
        SubscriptionLifecycleService $lifecycleService
    ): int {
        $processed = 0;
        $failed = 0;

        /*
         * Process subscriptions in chunks so the command
         * does not load the entire subscription table into memory.
         */
        Subscription::query()
            ->whereIn('status', [
                'trial',
                'active',
                'past_due',
                'grace_period',
                'restricted',
            ])
            ->orderBy('id')
            ->chunkById(100, function ($subscriptions) use (
                $lifecycleService,
                &$processed,
                &$failed
            ) {
                foreach ($subscriptions as $subscription) {
                    try {
                        $lifecycleService->process(
                            $subscription
                        );

                        $processed++;
                    } catch (\Throwable $e) {
                        $failed++;

                        report($e);

                        $this->error(
                            "Failed to process subscription {$subscription->id}."
                        );
                    }
                }
            });

        $this->info(
            "Subscription lifecycle processing completed."
        );

        $this->line(
            "Processed: {$processed}"
        );

        $this->line(
            "Failed: {$failed}"
        );

        return $failed > 0
            ? self::FAILURE
            : self::SUCCESS;
    }
}
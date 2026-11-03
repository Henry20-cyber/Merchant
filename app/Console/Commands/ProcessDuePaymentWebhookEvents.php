<?php

namespace App\Console\Commands;

use App\Domains\Payment\Services\PaymentWebhookEventProcessingService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('payment:webhooks:process-due')]
#[Description('Process due payment webhook retries and expired leases')]
class ProcessDuePaymentWebhookEvents extends Command
{
    public function handle(PaymentWebhookEventProcessingService $processingService): int
    {
        $result = $processingService->processDue();

        $this->info('Payment webhook retry processing completed.');
        $this->line('Processed: ' . $result['processed']);
        $this->line('Failed or under review: ' . $result['failed']);
        $this->line('Skipped after concurrent claim: ' . $result['skipped']);

        return self::SUCCESS;
    }
}

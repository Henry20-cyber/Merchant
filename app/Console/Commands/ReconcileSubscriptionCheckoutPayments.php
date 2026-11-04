<?php

namespace App\Console\Commands;

use App\Domains\Payment\Models\Payment;
use App\Domains\Payment\Services\PaymentConfirmationService;
use App\Domains\Payment\Models\SubscriptionCheckoutRecovery;
use Illuminate\Support\Facades\DB;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('payments:reconcile-subscription-checkouts {--limit=50 : Maximum pending checkouts to verify (1-100)}')]
#[Description('Reconcile a bounded batch of pending Paystack subscription checkout payments')]
class ReconcileSubscriptionCheckoutPayments extends Command
{
    private const MAX_ATTEMPTS = 8;

    private const RETRY_MINUTES = [5, 15, 30, 60, 120, 240, 360, 720];

    public function handle(PaymentConfirmationService $confirmationService): int
    {
        $limit = max(1, min(100, (int) $this->option('limit')));
        $ids = Payment::query()
            ->where('method', 'paystack')
            ->where('status', 'pending')
            ->where('metadata->type', 'subscription')
            ->where('created_at', '<=', now()->subMinutes(5))
            ->where('reconciliation_attempts', '<', self::MAX_ATTEMPTS)
            ->where(fn ($query) => $query->whereNull('next_reconciliation_at')->orWhere('next_reconciliation_at', '<=', now()))
            ->whereNotNull('reference')
            ->orderBy('created_at')
            ->limit($limit)
            ->pluck('id');

        $reconciled = 0;
        $unresolved = 0;
        $checked = 0;
        foreach ($ids as $id) {
            $payment = Payment::query()->find($id);
            if (! $payment) continue;
            $attempt = ((int) $payment->reconciliation_attempts) + 1;
            $nextAt = now()->addMinutes(self::RETRY_MINUTES[min($attempt - 1, count(self::RETRY_MINUTES) - 1)]);
            $claimed = Payment::query()
                ->whereKey($id)
                ->where('status', 'pending')
                ->where('reconciliation_attempts', '<', self::MAX_ATTEMPTS)
                ->where(fn ($query) => $query->whereNull('next_reconciliation_at')->orWhere('next_reconciliation_at', '<=', now()))
                ->update([
                    'reconciliation_attempts' => DB::raw('reconciliation_attempts + 1'),
                    'next_reconciliation_at' => $nextAt,
                    'updated_at' => now(),
                ]);
            if ($claimed !== 1) continue;
            $checked++;

            try {
                $payment = $confirmationService->confirm((string) $payment->reference);
                $payment->status === 'pending' ? $unresolved++ : $reconciled++;
            } catch (Throwable $exception) {
                // Verification failures stay unresolved; age or API errors are
                // never used as evidence that Paystack failed the transaction.
                report($exception);
                $unresolved++;
            }

            if ($attempt >= self::MAX_ATTEMPTS && Payment::query()->whereKey($id)->where('status', 'pending')->exists()) {
                DB::transaction(function () use ($id): void {
                    $pending = Payment::query()->whereKey($id)->where('status', 'pending')->lockForUpdate()->first();
                    if (! $pending?->checkout_recovery_id) return;
                    $recovery = SubscriptionCheckoutRecovery::query()->whereKey($pending->checkout_recovery_id)->lockForUpdate()->first();
                    if ($recovery && $recovery->status !== 'manual_review') {
                        $recovery->forceFill([
                            'status' => 'manual_review',
                            'manual_review_at' => now(),
                            'manual_review_reason' => 'Paystack outcome remained inconclusive after the bounded reconciliation attempts.',
                        ])->save();
                    }
                });
            }
        }

        $this->info("Checked: {$checked}; reconciled: {$reconciled}; unresolved: {$unresolved}.");
        return self::SUCCESS;
    }
}

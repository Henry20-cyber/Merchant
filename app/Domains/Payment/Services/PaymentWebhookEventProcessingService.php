<?php

namespace App\Domains\Payment\Services;

use App\Domains\Payment\Models\PaymentWebhookEvent;
use App\Domains\Subscription\Services\SubscriptionBillingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class PaymentWebhookEventProcessingService
{
    private const MAX_ATTEMPTS = 5;

    /** @var array<int, int> */
    private const RETRY_MINUTES = [1 => 1, 2 => 5, 3 => 15, 4 => 60];

    public function __construct(
        private PaymentConfirmationService $confirmationService,
        private SubscriptionRenewalService $renewalService,
        private SubscriptionPaymentFailureService $failureService,
        private SubscriptionBillingService $billingService,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public function recordAndProcess(
        array $payload,
        string $providerEventId,
        string $provider = 'paystack',
    ): PaymentWebhookEvent {
        $event = PaymentWebhookEvent::query()->firstOrCreate(
            [
                'provider' => $provider,
                'provider_event_id' => $providerEventId,
            ],
            [
                'event' => (string) data_get($payload, 'event', ''),
                'reference' => data_get($payload, 'data.reference'),
                'status' => 'received',
                'payload' => $payload,
            ],
        );

        return $this->processById((string) $event->getKey(), allowReceived: true);
    }

    /**
     * Attempt to claim and process one event. Scheduled callers leave
     * received rows alone; the future HTTP integration may opt in for a new row.
     */
    public function processById(string $eventId, bool $allowReceived = false): PaymentWebhookEvent
    {
        $claim = $this->claim($eventId, $allowReceived);
        if ($claim === null) {
            return PaymentWebhookEvent::query()->findOrFail($eventId);
        }

        try {
            $this->dispatch($claim['event']);
            if (! $this->complete($claim['event']->getKey(), $claim['token'])) {
                // Another worker reclaimed the lease while dispatch was running.
                // Its state is authoritative; this worker must make no follow-up update.
                return PaymentWebhookEvent::query()->findOrFail($eventId);
            }
        } catch (Throwable $exception) {
            report($exception);
            $this->fail(
                $claim['event']->getKey(),
                $claim['token'],
                $this->errorMessage($exception),
            );
        }

        return PaymentWebhookEvent::query()->findOrFail($eventId);
    }

    /** Process a bounded set of due retries and expired leases. */
    public function processDue(int $limit = 100): array
    {
        $this->moveLegacyFailuresToManualReview($limit);
        $this->moveExhaustedLeasesToManualReview($limit);

        $ids = PaymentWebhookEvent::query()
            ->where(function ($query): void {
                $query->where(function ($failed): void {
                    $failed->where('status', 'failed')
                        ->whereNotNull('next_attempt_at')
                        ->whereRaw("next_attempt_at <= (CURRENT_TIMESTAMP AT TIME ZONE 'UTC')")
                        ->where('attempts', '<', self::MAX_ATTEMPTS);
                })->orWhere(function ($processing): void {
                    $processing->where('status', 'processing')
                        ->whereNotNull('processing_started_at')
                        ->whereRaw("processing_started_at <= (CURRENT_TIMESTAMP AT TIME ZONE 'UTC') - INTERVAL '10 minutes'")
                        ->where('attempts', '<', self::MAX_ATTEMPTS);
                });
            })
            ->orderBy('updated_at')
            ->limit($limit)
            ->pluck('id');

        $processed = 0;
        $failed = 0;
        $skipped = 0;
        foreach ($ids as $id) {
            $before = PaymentWebhookEvent::query()->find($id);
            if (! $before) {
                continue;
            }

            $after = $this->processById((string) $id);
            if ($after->attempts <= $before->attempts) {
                $skipped++;
            } elseif ($after->status === 'processed') {
                $processed++;
            } elseif (in_array($after->status, ['failed', 'manual_review'], true)) {
                $failed++;
            } else {
                $skipped++;
            }
        }

        return compact('processed', 'failed', 'skipped');
    }

    private function claim(string $eventId, bool $allowReceived): ?array
    {
        // An expired fifth lease is exhausted and must never be claimed again.
        PaymentWebhookEvent::query()
            ->whereKey($eventId)
            ->where('status', 'processing')
            ->where('attempts', '>=', self::MAX_ATTEMPTS)
            ->whereNotNull('processing_started_at')
            ->whereRaw("processing_started_at <= (CURRENT_TIMESTAMP AT TIME ZONE 'UTC') - INTERVAL '10 minutes'")
            ->update([
                'status' => 'manual_review',
                'processing_token' => null,
                'processing_started_at' => null,
                'next_attempt_at' => null,
                'updated_at' => DB::raw('CURRENT_TIMESTAMP'),
            ]);

        $token = (string) Str::uuid();
        $query = PaymentWebhookEvent::query()
            ->whereKey($eventId)
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->where(function ($eligible) use ($allowReceived): void {
                if ($allowReceived) {
                    $eligible->where(function ($received): void {
                        $received->where('status', 'received')
                            ->whereNull('processing_token');
                    });
                }

                $eligible->orWhere(function ($failed): void {
                    $failed->where('status', 'failed')
                        ->whereNotNull('next_attempt_at')
                        ->whereRaw("next_attempt_at <= (CURRENT_TIMESTAMP AT TIME ZONE 'UTC')");
                })->orWhere(function ($processing): void {
                    $processing->where('status', 'processing')
                        ->whereNotNull('processing_started_at')
                        ->whereRaw("processing_started_at <= (CURRENT_TIMESTAMP AT TIME ZONE 'UTC') - INTERVAL '10 minutes'");
                });
            });

        $updated = $query->update([
            'status' => 'processing',
            'processing_token' => $token,
            'processing_started_at' => DB::raw("CURRENT_TIMESTAMP AT TIME ZONE 'UTC'"),
            'attempts' => DB::raw('attempts + 1'),
            'next_attempt_at' => null,
            'error_message' => null,
            'processed_at' => null,
            'updated_at' => DB::raw('CURRENT_TIMESTAMP'),
        ]);

        if ($updated !== 1) {
            return null;
        }

        $event = PaymentWebhookEvent::query()
            ->whereKey($eventId)
            ->where('status', 'processing')
            ->where('processing_token', $token)
            ->first();

        return $event ? ['event' => $event, 'token' => $token] : null;
    }

    public function complete(string $eventId, string $token): bool
    {
        return PaymentWebhookEvent::query()
            ->whereKey($eventId)
            ->where('status', 'processing')
            ->where('processing_token', $token)
            ->update([
                'status' => 'processed',
                'processed_at' => DB::raw('CURRENT_TIMESTAMP'),
                'processing_token' => null,
                'processing_started_at' => null,
                'next_attempt_at' => null,
                'error_message' => null,
                'updated_at' => DB::raw('CURRENT_TIMESTAMP'),
            ]) === 1;
    }

    public function fail(string $eventId, string $token, string $message): bool
    {
        foreach (self::RETRY_MINUTES as $attempt => $minutes) {
            $affected = PaymentWebhookEvent::query()
                ->whereKey($eventId)
                ->where('status', 'processing')
                ->where('processing_token', $token)
                ->where('attempts', $attempt)
                ->update([
                    'status' => 'failed',
                    'error_message' => $message,
                    'processing_token' => null,
                    'processing_started_at' => null,
                    'next_attempt_at' => DB::raw("(CURRENT_TIMESTAMP AT TIME ZONE 'UTC') + INTERVAL '{$minutes} minutes'"),
                    'processed_at' => null,
                    'updated_at' => DB::raw('CURRENT_TIMESTAMP'),
                ]);
            if ($affected === 1) {
                return true;
            }
        }

        return PaymentWebhookEvent::query()
            ->whereKey($eventId)
            ->where('status', 'processing')
            ->where('processing_token', $token)
            ->where('attempts', '>=', self::MAX_ATTEMPTS)
            ->update([
                'status' => 'manual_review',
                'error_message' => $message,
                'processing_token' => null,
                'processing_started_at' => null,
                'next_attempt_at' => null,
                'processed_at' => null,
                'updated_at' => DB::raw('CURRENT_TIMESTAMP'),
            ]) === 1;
    }

    private function dispatch(PaymentWebhookEvent $event): void
    {
        $payload = $event->payload ?? [];
        $eventName = $event->event;
        $reference = data_get($payload, 'data.reference');

        if (! in_array($eventName, [
            'charge.success',
            'charge.failed',
            'invoice.payment_failed',
            'subscription.not_renew',
            'subscription.disable',
        ], true)) {
            return;
        }

        if ($eventName === 'charge.success') {
            if (! $reference) {
                throw ValidationException::withMessages(['reference' => 'Payment reference missing.']);
            }

            $subscriptionCode = data_get($payload, 'data.subscription.subscription_code')
                ?? data_get($payload, 'data.subscription_code')
                ?? data_get($payload, 'data.plan.subscription_code');

            if ($subscriptionCode) {
                $this->renewalService->renew([
                    'reference' => $reference,
                    'customer_code' => data_get($payload, 'data.customer.customer_code'),
                    'subscription_code' => $subscriptionCode,
                    'amount' => data_get($payload, 'data.amount'),
                    'currency' => data_get($payload, 'data.currency'),
                    'paid_at' => data_get($payload, 'data.paid_at'),
                ]);
            } else {
                $this->confirmationService->confirm($reference);
            }
        }

        if ($eventName === 'invoice.payment_failed') {
            $customerCode = data_get($payload, 'data.customer.customer_code');
            $subscriptionCode = data_get($payload, 'data.subscription.subscription_code')
                ?? data_get($payload, 'data.subscription_code');

            if ($customerCode && $subscriptionCode) {
                $this->failureService->fail([
                    'customer_code' => $customerCode,
                    'subscription_code' => $subscriptionCode,
                    'reference' => data_get($payload, 'data.transaction.reference')
                        ?? data_get($payload, 'data.reference'),
                    'amount' => data_get($payload, 'data.transaction.amount')
                        ?? data_get($payload, 'data.amount'),
                    'currency' => data_get($payload, 'data.transaction.currency')
                        ?? data_get($payload, 'data.currency'),
                ]);
            }
        }

        if (in_array($eventName, ['subscription.not_renew', 'subscription.disable'], true)) {
            $subscriptionCode = data_get($payload, 'data.subscription_code')
                ?? data_get($payload, 'data.subscription.subscription_code');

            if ($subscriptionCode) {
                if ($eventName === 'subscription.not_renew') {
                    $this->billingService->markNonRenewing('paystack', $subscriptionCode);
                } else {
                    $this->billingService->markDisabled('paystack', $subscriptionCode);
                }
            }
        }
    }

    private function moveLegacyFailuresToManualReview(int $limit): int
    {
        $ids = PaymentWebhookEvent::query()
            ->where('status', 'failed')
            ->where('attempts', 0)
            ->whereNull('next_attempt_at')
            ->orderBy('updated_at')
            ->limit($limit)
            ->pluck('id');

        if ($ids->isEmpty()) {
            return 0;
        }

        return PaymentWebhookEvent::query()
            ->whereIn('id', $ids)
            ->where('status', 'failed')
            ->where('attempts', 0)
            ->whereNull('next_attempt_at')
            ->update([
                'status' => 'manual_review',
                'updated_at' => DB::raw('CURRENT_TIMESTAMP'),
            ]);
    }

    private function moveExhaustedLeasesToManualReview(int $limit): int
    {
        $ids = PaymentWebhookEvent::query()
            ->where('status', 'processing')
            ->where('attempts', '>=', self::MAX_ATTEMPTS)
            ->whereNotNull('processing_started_at')
            ->whereRaw("processing_started_at <= (CURRENT_TIMESTAMP AT TIME ZONE 'UTC') - INTERVAL '10 minutes'")
            ->orderBy('processing_started_at')
            ->limit($limit)
            ->pluck('id');

        if ($ids->isEmpty()) {
            return 0;
        }

        return PaymentWebhookEvent::query()
            ->whereIn('id', $ids)
            ->where('status', 'processing')
            ->where('attempts', '>=', self::MAX_ATTEMPTS)
            ->whereRaw("processing_started_at <= (CURRENT_TIMESTAMP AT TIME ZONE 'UTC') - INTERVAL '10 minutes'")
            ->update([
                'status' => 'manual_review',
                'processing_token' => null,
                'processing_started_at' => null,
                'next_attempt_at' => null,
                'updated_at' => DB::raw('CURRENT_TIMESTAMP'),
            ]);
    }

    private function errorMessage(Throwable $exception): string
    {
        if ($exception instanceof ValidationException) {
            foreach ($exception->errors() as $messages) {
                if (! empty($messages)) {
                    return (string) $messages[0];
                }
            }
        }

        return $exception->getMessage() ?: 'Webhook processing failed.';
    }
}

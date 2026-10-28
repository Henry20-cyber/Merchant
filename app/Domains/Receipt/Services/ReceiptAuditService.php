<?php

namespace App\Domains\Receipt\Services;

use App\Domains\Organization\Models\Business;
use App\Domains\Receipt\Models\Receipt;
use App\Domains\Receipt\Models\ReceiptAuditEvent;
use App\Domains\Payment\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ReceiptAuditService
{
    public function record(
        Business $business,
        string $eventType,
        array $payload = [],
        ?Receipt $receipt = null,
        ?Payment $payment = null,
        ?User $actor = null,
    ): ReceiptAuditEvent {
        return DB::transaction(function () use (
            $business,
            $eventType,
            $payload,
            $receipt,
            $payment,
            $actor,
        ): ReceiptAuditEvent {
            // Serialize audit-chain writes per business.
            Business::query()
                ->whereKey($business->id)
                ->lockForUpdate()
                ->firstOrFail();

            $previous = ReceiptAuditEvent::query()
                ->where('business_id', $business->id)
                ->orderByDesc('event_sequence')
                ->first();

            $sequence = ((int) ($previous?->event_sequence ?? 0)) + 1;

            $canonical = [
                'business_id' => (string) $business->id,
                'receipt_id' => $receipt?->id,
                'payment_id' => $payment?->id,
                'actor_id' => $actor === null ? null : (int) $actor->id,
                'event_sequence' => $sequence,
                'event_type' => $eventType,
                'event_payload' => $payload,
                'previous_hash' => $previous?->event_hash,
            ];

            $eventHash = hash_hmac(
                'sha256',
                $this->canonicalJson($canonical),
                $this->signingKey(),
            );

            return ReceiptAuditEvent::create([
                ...$canonical,
                'event_hash' => $eventHash,
                'created_at' => now(),
            ]);
        });
    }

    public function verifyChain(string $businessId): bool
    {
        $previousHash = null;

        $events = ReceiptAuditEvent::query()
            ->where('business_id', $businessId)
            ->orderBy('event_sequence')
            ->get();

        foreach ($events as $event) {
            $canonical = [
                'business_id' => (string) $event->business_id,
                'receipt_id' => $event->receipt_id,
                'payment_id' => $event->payment_id,
                'actor_id' => $event->actor_id === null
                    ? null
                    : (int) $event->actor_id,
                'event_sequence' => (int) $event->event_sequence,
                'event_type' => $event->event_type,
                'event_payload' => $event->event_payload ?? [],
                'previous_hash' => $previousHash,
            ];

            $expected = hash_hmac(
                'sha256',
                $this->canonicalJson($canonical),
                $this->signingKey(),
            );

            if (
                ! hash_equals($expected, (string) $event->event_hash)
                || $event->previous_hash !== $previousHash
            ) {
                return false;
            }

            $previousHash = $event->event_hash;
        }

        return true;
    }

    public function signSnapshot(Receipt $receipt): array
    {
        $hash = hash(
            'sha256',
            $this->canonicalJson($receipt->snapshot ?? []),
        );

        $signature = hash_hmac(
            'sha256',
            $receipt->id . '|' . $receipt->receipt_number . '|' . $hash,
            $this->signingKey(),
        );

        return [$hash, $signature];
    }

    public function snapshotIsAuthentic(Receipt $receipt): bool
    {
        if (! $receipt->snapshot_hash || ! $receipt->snapshot_signature) {
            return false;
        }

        $currentHash = hash(
            'sha256',
            $this->canonicalJson($receipt->snapshot ?? []),
        );

        $expectedSignature = hash_hmac(
            'sha256',
            $receipt->id . '|' . $receipt->receipt_number . '|' . $receipt->snapshot_hash,
            $this->signingKey(),
        );

        return hash_equals($receipt->snapshot_hash, $currentHash)
            && hash_equals(
                $receipt->snapshot_signature,
                $expectedSignature,
            );
    }

    private function canonicalJson(array $value): string
    {
        $normalize = function (mixed $item) use (&$normalize): mixed {
            if (! is_array($item)) {
                return $item;
            }

            if (array_is_list($item)) {
                return array_map($normalize, $item);
            }

            ksort($item, SORT_STRING);

            foreach ($item as $key => $nested) {
                $item[$key] = $normalize($nested);
            }

            return $item;
        };

        return json_encode(
            $normalize($value),
            JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_THROW_ON_ERROR,
        );
    }

    private function signingKey(): string
    {
        $key = config('receipt-verification.key');

        if (! is_string($key) || trim($key) === '') {
            throw new RuntimeException(
                'RECEIPT_VERIFICATION_KEY must be configured for receipt integrity signing.',
            );
        }

        return $key;
    }
}

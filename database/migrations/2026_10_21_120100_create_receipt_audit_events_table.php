
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // Validate the key before creating the audit table.
        $key = config('receipt-verification.key');

        if (! is_string($key) || trim($key) === '') {
            throw new RuntimeException(
                'RECEIPT_VERIFICATION_KEY must be configured before migrating receipt audit history.'
            );
        }

        Schema::create('receipt_audit_events', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('business_id')
                ->constrained('businesses')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignUuid('receipt_id')
                ->nullable()
                ->constrained('receipts')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignUuid('payment_id')
                ->nullable()
                ->constrained('payments')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            // Preserve the actor ID if its user account is later removed.
            $table->unsignedBigInteger('actor_id')->nullable();

            $table->unsignedBigInteger('event_sequence');
            $table->string('event_type', 80);
            $table->jsonb('event_payload')->default('{}');
            $table->char('previous_hash', 64)->nullable();
            $table->char('event_hash', 64);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['business_id', 'event_sequence']);
            $table->index(['business_id', 'created_at']);
            $table->index(['receipt_id', 'created_at']);
        });

        /*
         * Keep canonicalization identical to ReceiptAuditService.
         * Associative keys are sorted recursively; list order is preserved.
         */
        $normalize = function (mixed $item) use (&$normalize): mixed {
            if (! is_array($item)) {
                return $item;
            }

            if (array_is_list($item)) {
                return array_map($normalize, $item);
            }

            ksort($item, SORT_STRING);

            foreach ($item as $field => $value) {
                $item[$field] = $normalize($value);
            }

            return $item;
        };

        $sequences = [];
        $previousHashes = [];

        /*
         * cursor() avoids materializing every receipt in memory.
         * Keep this ordering stable within each business's chain.
         */
        foreach (
            DB::table('receipts')
                ->orderBy('business_id')
                ->orderBy('issued_at')
                ->orderBy('id')
                ->cursor() as $receipt
        ) {
            $businessId = (string) $receipt->business_id;

            $sequence = ($sequences[$businessId] ?? 0) + 1;
            $sequences[$businessId] = $sequence;

            $actorId = $receipt->issued_by === null
                ? null
                : (int) $receipt->issued_by;

            $payload = [
                'receipt_number' => $receipt->receipt_number,
                'receipt_type' => $receipt->receipt_type ?? 'sale',
                'snapshot_hash' => $receipt->snapshot_hash,
                'migration_backfill' => true,
            ];

            $previousHash = $previousHashes[$businessId] ?? null;

            // This field structure must match ReceiptAuditService exactly.
            $canonical = [
                'business_id' => $businessId,
                'receipt_id' => $receipt->id,
                'payment_id' => $receipt->payment_id,
                'actor_id' => $actorId,
                'event_sequence' => $sequence,
                'event_type' => 'receipt_issued_legacy_backfill',
                'event_payload' => $payload,
                'previous_hash' => $previousHash,
            ];

            $canonicalJson = json_encode(
                $normalize($canonical),
                JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_THROW_ON_ERROR
            );

            $eventHash = hash_hmac('sha256', $canonicalJson, $key);

            DB::table('receipt_audit_events')->insert([
                'id' => (string) Str::uuid(),
                'business_id' => $businessId,
                'receipt_id' => $receipt->id,
                'payment_id' => $receipt->payment_id,
                'actor_id' => $actorId,
                'event_sequence' => $sequence,
                'event_type' => 'receipt_issued_legacy_backfill',
                'event_payload' => json_encode(
                    $payload,
                    JSON_UNESCAPED_SLASHES
                        | JSON_UNESCAPED_UNICODE
                        | JSON_THROW_ON_ERROR
                ),
                'previous_hash' => $previousHash,
                'event_hash' => $eventHash,
                'created_at' => $receipt->issued_at ?? now(),
            ]);

            $previousHashes[$businessId] = $eventHash;
        }

        /*
         * PostgreSQL-level protection in addition to the model guards.
         */
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION prevent_receipt_audit_event_mutation()
                RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'receipt audit events are append-only';
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER receipt_audit_events_no_update_delete
                BEFORE UPDATE OR DELETE ON receipt_audit_events
                FOR EACH ROW EXECUTE FUNCTION prevent_receipt_audit_event_mutation();

                CREATE TRIGGER receipt_audit_events_no_truncate
                BEFORE TRUNCATE ON receipt_audit_events
                FOR EACH STATEMENT EXECUTE FUNCTION prevent_receipt_audit_event_mutation();
            SQL);
        }
    }

    public function down(): void
    {
        if (
            Schema::hasTable('receipt_audit_events')
            && DB::table('receipt_audit_events')->exists()
        ) {
            throw new RuntimeException(
                'Cannot roll back receipt audit history while audit events exist. '
                    . 'Preserve the append-only audit trail.'
            );
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(
                'DROP TRIGGER IF EXISTS receipt_audit_events_no_update_delete ON receipt_audit_events'
            );

            DB::unprepared(
                'DROP TRIGGER IF EXISTS receipt_audit_events_no_truncate ON receipt_audit_events'
            );

            DB::unprepared(
                'DROP FUNCTION IF EXISTS prevent_receipt_audit_event_mutation()'
            );
        }

        Schema::dropIfExists('receipt_audit_events');
    }
};

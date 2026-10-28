<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Validate the signing key before changing the schema.
        $key = config('receipt-verification.key');

        if (! is_string($key) || trim($key) === '') {
            throw new RuntimeException(
                'RECEIPT_VERIFICATION_KEY must be configured before migrating receipt verification.'
            );
        }

        Schema::table('receipts', function (Blueprint $table) {
            $table->string('receipt_type', 30)->default('sale');

            $table->foreignUuid('payment_id')
                ->nullable()
                ->after('sale_id')
                ->constrained('payments')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->string('verification_token', 64)->nullable()->unique();
            $table->char('snapshot_hash', 64)->nullable();
            $table->char('snapshot_signature', 64)->nullable();
        });

        Schema::table('receipts', function (Blueprint $table) {
            $table->dropUnique('receipts_sale_id_unique');
            $table->uuid('sale_id')->nullable()->change();
        });

        // One sale receipt per sale and one payment receipt per payment.
        DB::statement(
            "CREATE UNIQUE INDEX receipts_one_sale_receipt_unique
             ON receipts (sale_id)
             WHERE receipt_type = 'sale' AND sale_id IS NOT NULL"
        );

        DB::statement(
            "CREATE UNIQUE INDEX receipts_one_payment_receipt_unique
             ON receipts (payment_id)
             WHERE receipt_type = 'payment' AND payment_id IS NOT NULL"
        );

        $normalize = function (mixed $item) use (&$normalize): mixed {
            if (! is_array($item)) {
                return $item;
            }

            if (array_is_list($item)) {
                return array_map($normalize, $item);
            }

            ksort($item, SORT_STRING);

            foreach ($item as $key => $value) {
                $item[$key] = $normalize($value);
            }

            return $item;
        };

        // Backfill existing receipts in batches to limit memory usage.
        DB::table('receipts')
            ->whereNull('verification_token')
            ->orderBy('id')
            ->chunkById(500, function ($receipts) use ($key, $normalize) {
                foreach ($receipts as $receipt) {
                    if ($receipt->snapshot === null) {
                        $snapshot = [];
                    } elseif (is_array($receipt->snapshot)) {
                        $snapshot = $receipt->snapshot;
                    } else {
                        $snapshot = json_decode(
                            (string) $receipt->snapshot,
                            true,
                            512,
                            JSON_THROW_ON_ERROR
                        );
                    }

                    if (! is_array($snapshot)) {
                        throw new RuntimeException(
                            "Receipt {$receipt->id} has an invalid snapshot."
                        );
                    }

                    $snapshotJson = json_encode(
                        $normalize($snapshot),
                        JSON_UNESCAPED_SLASHES
                            | JSON_UNESCAPED_UNICODE
                            | JSON_THROW_ON_ERROR
                    );

                    $snapshotHash = hash('sha256', $snapshotJson);

                    $signature = hash_hmac(
                        'sha256',
                        $receipt->id . '|'
                            . $receipt->receipt_number . '|'
                            . $snapshotHash,
                        $key
                    );

                    DB::table('receipts')
                        ->where('id', $receipt->id)
                        ->update([
                            'verification_token' => bin2hex(random_bytes(32)),
                            'snapshot_hash' => $snapshotHash,
                            'snapshot_signature' => $signature,
                        ]);
                }
            });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE receipts
                 ADD CONSTRAINT receipts_type_check
                 CHECK (
                     (
                         receipt_type = 'sale'
                         AND sale_id IS NOT NULL
                         AND payment_id IS NULL
                     )
                     OR
                     (
                         receipt_type = 'payment'
                         AND sale_id IS NOT NULL
                         AND payment_id IS NOT NULL
                     )
                 )"
            );
        }
    }

    public function down(): void
    {
        // Do not silently destroy payment receipts during a rollback.
        if (
            Schema::hasColumn('receipts', 'receipt_type')
            && DB::table('receipts')
                ->where('receipt_type', 'payment')
                ->exists()
        ) {
            throw new RuntimeException(
                'Cannot roll back receipt verification while payment receipts exist.'
            );
        }

        // The original schema requires sale_id to be non-null.
        if (
            Schema::hasColumn('receipts', 'sale_id')
            && DB::table('receipts')->whereNull('sale_id')->exists()
        ) {
            throw new RuntimeException(
                'Cannot restore the original receipts schema while sale_id contains NULL values.'
            );
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE receipts DROP CONSTRAINT IF EXISTS receipts_type_check'
            );
        }

        DB::statement('DROP INDEX IF EXISTS receipts_one_payment_receipt_unique');
        DB::statement('DROP INDEX IF EXISTS receipts_one_sale_receipt_unique');

        Schema::table('receipts', function (Blueprint $table) {
            $table->dropUnique(['verification_token']);
            $table->dropForeign(['payment_id']);

            $table->dropColumn([
                'receipt_type',
                'payment_id',
                'verification_token',
                'snapshot_hash',
                'snapshot_signature',
            ]);

            $table->uuid('sale_id')->nullable(false)->change();
            $table->unique('sale_id');
        });
    }
};

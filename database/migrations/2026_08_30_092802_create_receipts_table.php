<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipts', function (Blueprint $table) {
            /*
             * Receipt identifier.
             */
            $table->uuid('id')->primary();

            /*
             * MerchantOS business tenant.
             */
            $table->foreignUuid('business_id')
                ->constrained('businesses')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            /*
             * One official receipt belongs to one sale.
             *
             * UNIQUE is critical:
             *
             * one sale = one official receipt.
             *
             * This also gives us database-level idempotency.
             */
            $table->foreignUuid('sale_id')
                ->unique()
                ->constrained('sales')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            /*
             * Human-readable receipt identifier.
             *
             * Example:
             *
             * RCPT-000001
             */
            $table->string('receipt_number', 100);

            /*
             * Receipt lifecycle.
             *
             * issued
             * voided
             */
            $table->string('status', 50)
                ->default('issued');

            /*
             * User who issued/generated the receipt.
             *
             * Nullable because a future automated process may
             * generate receipts without an authenticated user.
             */
            $table->foreignId('issued_by')
                ->nullable()
                ->constrained('users')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            /*
             * Official issuance timestamp.
             */
            $table->timestamp('issued_at');

            $table->timestamps();

            /*
             * Receipt numbers are unique inside a business.
             *
             * Business A can have:
             *
             * RCPT-000001
             *
             * while Business B can also have:
             *
             * RCPT-000001
             */
            $table->unique([
                'business_id',
                'receipt_number',
            ]);

            /*
             * Business receipt history.
             */
            $table->index([
                'business_id',
                'created_at',
            ]);

            /*
             * Useful for receipt status filtering.
             */
            $table->index([
                'business_id',
                'status',
            ]);

            /*
             * Useful for locating receipts issued by a user.
             */
            $table->index('issued_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipts');
    }
};
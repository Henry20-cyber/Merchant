<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            /*
             * Payment identifier.
             */
            $table->uuid('id')->primary();

            /*
             * Every payment belongs to exactly one business.
             *
             * This is essential for MerchantOS multi-tenancy.
             */
            $table->foreignUuid('business_id')
                ->constrained('businesses')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            /*
             * The sale this payment belongs to.
             *
             * A sale can have multiple payments.
             */
            $table->foreignUuid('sale_id')
                ->constrained('sales')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            /*
             * Amount represented by this payment.
             */
            $table->decimal('amount', 14, 2);

            /*
             * Examples:
             *
             * cash
             * bank_transfer
             * card
             * mobile_money
             * other
             */
            $table->string('method', 50);

            /*
             * Payment lifecycle.
             *
             * pending
             * paid
             * failed
             * refunded
             * voided
             */
            $table->string('status', 50)
                ->default('pending');

            /*
             * Optional payment/provider reference.
             *
             * Useful for bank transfers, card transactions,
             * payment gateways, etc.
             */
            $table->string('reference', 255)
                ->nullable();

            /*
             * Additional provider-specific information.
             *
             * PostgreSQL JSONB is used because MerchantOS
             * uses PostgreSQL in production.
             */
            $table->jsonb('metadata')
                ->nullable();

            /*
             * When the payment was actually received/confirmed.
             */
            $table->timestamp('paid_at')
                ->nullable();

            $table->timestamps();

            /*
             * Common business-level queries.
             */
            $table->index([
                'business_id',
                'created_at',
            ]);

            /*
             * Quickly retrieve payments belonging to a sale.
             */
            $table->index([
                'business_id',
                'sale_id',
            ]);

            /*
             * Useful for payment reconciliation and reporting.
             */
            $table->index([
                'business_id',
                'status',
            ]);

            /*
             * References are searched frequently when
             * reconciling transfers or gateway payments.
             */
            $table->index([
                'business_id',
                'reference',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
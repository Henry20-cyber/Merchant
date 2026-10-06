<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credits', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('business_id')
                ->constrained('businesses')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignUuid('customer_id')
                ->constrained('customers')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignUuid('sale_id')
                ->constrained('sales')
                ->cascadeOnUpdate()
                ->restrictOnDelete()
                ->unique();

            /*
             * Original value of the credit sale.
             *
             * Payments are recorded in the existing payments table.
             * Outstanding balance is calculated from this amount
             * minus paid payments against the sale.
             */
            $table->decimal('original_amount', 14, 2);

            /*
             * Optional date by which the customer should settle
             * the credit.
             */
            $table->timestamp('due_at')->nullable();

            /*
             * outstanding:
             * No payment has been made.
             *
             * partial:
             * One or more payments have been made, but a balance
             * remains.
             *
             * settled:
             * The credit has been fully paid.
             */
            $table->string('status', 50)
                ->default('outstanding');

            $table->timestamps();

            $table->index([
                'business_id',
                'status',
            ]);

            $table->index([
                'business_id',
                'customer_id',
            ]);

            $table->index([
                'business_id',
                'due_at',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credits');
    }
};
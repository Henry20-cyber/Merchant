<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('business_id')
                ->constrained('businesses')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            /*
             * User who created/processed the sale.
             */
            $table->foreignId('cashier_id')
                ->constrained('users')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            /*
             * Customer support will be added later.
             * Keep this nullable for walk-in sales.
             */
            $table->uuid('customer_id')->nullable();

            $table->decimal('subtotal', 14, 2)
                ->default(0);

            $table->decimal('discount', 14, 2)
                ->default(0);

            $table->decimal('tax', 14, 2)
                ->default(0);

            $table->decimal('total', 14, 2)
                ->default(0);

            $table->string('payment_method', 50)
                ->default('cash');

            $table->string('payment_status', 50)
                ->default('paid');

            /*
             * completed:
             * Sale successfully completed.
             *
             * cancelled:
             * Sale was cancelled after creation.
             */
            $table->string('status', 50)
                ->default('completed');

            $table->timestamps();

            $table->index([
                'business_id',
                'created_at',
            ]);

            $table->index([
                'business_id',
                'status',
            ]);

            $table->index([
                'business_id',
                'payment_status',
            ]);

            $table->index('cashier_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales');
    }
};
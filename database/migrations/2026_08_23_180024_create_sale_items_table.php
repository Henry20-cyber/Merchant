<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_items', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('sale_id')
                ->constrained('sales')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->foreignUuid('product_id')
                ->constrained('products')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignUuid('product_unit_id')
                ->constrained('product_units')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            /*
             * Quantity sold using this product unit.
             */
            $table->decimal('quantity', 14, 4);

            /*
             * Snapshot of the selling price at
             * the exact time of the sale.
             */
            $table->decimal('unit_price', 14, 2);

            /*
             * Snapshot of the cost price at
             * the exact time of the sale.
             *
             * This is important for calculating
             * historical gross profit.
             */
            $table->decimal('unit_cost', 14, 2);

            $table->decimal('discount', 14, 2)
                ->default(0);

            $table->decimal('total', 14, 2);

            $table->timestamps();

            $table->index('sale_id');

            $table->index([
                'product_id',
                'created_at',
            ]);

            $table->index([
                'product_unit_id',
                'created_at',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_items');
    }
};
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stocks', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('business_id')
                ->constrained('businesses')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignUuid('product_id')
                ->constrained('products')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignUuid('product_unit_id')
                ->constrained('product_units')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            /*
             * Current available quantity.
             *
             * DECIMAL is used because ProductUnit quantities
             * already support fractional quantities.
             */
            $table->decimal('quantity', 12, 4)
                ->default(0);

            /*
             * Quantity at which the product should be
             * considered low in stock.
             */
            $table->decimal('reorder_level', 12, 4)
                ->default(0);

            $table->timestamps();

            /*
             * A product unit has exactly one stock balance
             * within a business.
             */
            $table->unique([
                'business_id',
                'product_unit_id',
            ]);

            $table->index([
                'business_id',
                'product_id',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stocks');
    }
};
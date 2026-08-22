<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_units', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('business_id')
                ->constrained('businesses')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignUuid('product_id')
                ->constrained('products')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            /*
             * Human-readable unit name:
             * piece, pack, carton, bottle, crate, box, etc.
             */
            $table->string('name', 50);

            /*
             * Number of base units represented by this unit.
             *
             * Example:
             * Piece  = 1
             * Pack   = 6
             * Carton = 24
             */
            $table->decimal('quantity', 15, 4);

            $table->decimal('cost_price', 15, 2);

            $table->decimal('selling_price', 15, 2);

            $table->string('currency', 3)
                ->default('NGN');

            $table->boolean('is_base_unit')
                ->default(false);

            $table->boolean('is_sellable')
                ->default(true);

            $table->boolean('is_purchasable')
                ->default(true);

            $table->timestamps();

            $table->unique([
                'business_id',
                'product_id',
                'name',
            ]);

            $table->index([
                'business_id',
                'product_id',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_units');
    }
};
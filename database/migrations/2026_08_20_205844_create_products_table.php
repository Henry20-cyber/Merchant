<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('business_id')
                ->constrained('businesses')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->string('name');

            $table->string('sku');

            $table->string('barcode')->nullable();

            $table->text('description')->nullable();

            $table->decimal('cost_price', 15, 2)
                ->default(0);

            $table->decimal('selling_price', 15, 2)
                ->default(0);

            $table->string('currency', 3)
                ->default('NGN');

            $table->string('unit', 50)
                ->default('piece');

            $table->string('status', 20)
                ->default('active');

            $table->timestamps();

            $table->softDeletes();

            /*
             * SKU only needs to be unique within
             * a particular business.
             */
            $table->unique([
                'business_id',
                'sku',
            ]);

            /*
             * Useful for barcode lookup.
             */
            $table->index([
                'business_id',
                'barcode',
            ]);

            /*
             * Useful for filtering active/inactive products.
             */
            $table->index([
                'business_id',
                'status',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_barcodes', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('business_id')
                ->constrained('businesses')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignUuid('product_id')
                ->constrained('products')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->string('barcode');

            $table->string('type', 50)
                ->default('manufacturer');

            $table->boolean('is_primary')
                ->default(false);

            $table->timestamps();

            /*
             * A barcode must identify at most one product
             * within a business.
             */
            $table->unique([
                'business_id',
                'barcode',
            ]);

            /*
             * Speeds up retrieval of all barcodes belonging
             * to a product.
             */
            $table->index([
                'business_id',
                'product_id',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_barcodes');
    }
};
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
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

            $table->foreignUuid('stock_id')
                ->constrained('stocks')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->string('type', 50);

            $table->decimal('quantity', 14, 4);

            $table->decimal('quantity_before', 14, 4);

            $table->decimal('quantity_after', 14, 4);

            $table->string('reference_type')->nullable();

            $table->uuid('reference_id')->nullable();

            $table->text('note')->nullable();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index([
                'business_id',
                'product_id',
            ]);

            $table->index([
                'business_id',
                'product_unit_id',
            ]);

            $table->index([
                'stock_id',
                'created_at',
            ]);

            $table->index([
                'reference_type',
                'reference_id',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
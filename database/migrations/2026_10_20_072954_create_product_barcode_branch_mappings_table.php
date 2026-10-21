<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_barcode_branch_mappings', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('business_id')
                ->constrained('businesses')
                ->cascadeOnDelete();

            $table->foreignUuid('branch_id')
                ->constrained('branches')
                ->cascadeOnDelete();

            $table->foreignUuid('product_barcode_id')
                ->constrained('product_barcodes')
                ->cascadeOnDelete();

            $table->timestamps();

            $table->unique(
                ['business_id', 'branch_id', 'product_barcode_id'],
                'pb_branch_mapping_unique'
            );

            $table->index(
                ['business_id', 'branch_id'],
                'pb_branch_mapping_lookup_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_barcode_branch_mappings');
    }
};
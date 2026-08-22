<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_barcodes', function (Blueprint $table) {
            $table->foreignUuid('product_unit_id')
                ->nullable()
                ->after('product_id')
                ->constrained('product_units')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->index([
                'business_id',
                'product_unit_id',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('product_barcodes', function (Blueprint $table) {
            $table->dropForeign([
                'product_unit_id',
            ]);

            $table->dropIndex([
                'business_id',
                'product_unit_id',
            ]);

            $table->dropColumn('product_unit_id');
        });
    }
};
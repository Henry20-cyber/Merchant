<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stocks', function (Blueprint $table) {
            $table->dropForeign(['product_unit_id']);
            $table->dropUnique([
                'business_id',
                'product_unit_id',
            ]);

            $table->dropColumn('product_unit_id');
        });

        Schema::table('stocks', function (Blueprint $table) {
            $table->unique([
                'business_id',
                'product_id',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('stocks', function (Blueprint $table) {
            $table->dropUnique([
                'business_id',
                'product_id',
            ]);

            $table->foreignUuid('product_unit_id')
                ->constrained('product_units')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->unique([
                'business_id',
                'product_unit_id',
            ]);
        });
    }
};
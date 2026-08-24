<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
            $table->dropForeign(['product_unit_id']);

            $table->foreignUuid('product_id')
                ->nullable()
                ->change();

            $table->foreignUuid('product_unit_id')
                ->nullable()
                ->change();

            $table->foreign('product_id')
                ->references('id')
                ->on('products')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreign('product_unit_id')
                ->references('id')
                ->on('product_units')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        /*
         * Before reversing this migration, all service sale items
         * must have been removed or converted to product sale items.
         */

        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
            $table->dropForeign(['product_unit_id']);

            $table->foreignUuid('product_id')
                ->nullable(false)
                ->change();

            $table->foreignUuid('product_unit_id')
                ->nullable(false)
                ->change();

            $table->foreign('product_id')
                ->references('id')
                ->on('products')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreign('product_unit_id')
                ->references('id')
                ->on('product_units')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
        });
    }
};
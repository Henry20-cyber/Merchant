<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->foreignUuid('service_id')
                ->nullable()
                ->after('product_unit_id')
                ->constrained('services')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->index('service_id');
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropForeign(['service_id']);
            $table->dropIndex(['service_id']);
            $table->dropColumn('service_id');
        });
    }
};
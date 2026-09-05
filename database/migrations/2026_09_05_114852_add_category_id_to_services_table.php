<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->foreignUuid('category_id')
                ->nullable()
                ->after('business_id')
                ->constrained('categories')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->index([
                'business_id',
                'category_id',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropForeign(['category_id']);
            $table->dropIndex([
                'services_business_id_category_id_index',
            ]);
            $table->dropColumn('category_id');
        });
    }
};
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'cost_price',
                'selling_price',
                'currency',
                'unit',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('cost_price', 15, 2)
                ->nullable();

            $table->decimal('selling_price', 15, 2)
                ->nullable();

            $table->string('currency', 3)
                ->default('NGN');

            $table->string('unit', 50)
                ->default('piece');
        });
    }
};
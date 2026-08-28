<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_capabilities', function (Blueprint $table) {
            $table->uuid('business_id')->primary();

            $table->boolean('products_enabled')->default(false);
            $table->boolean('services_enabled')->default(false);

            $table->timestamps();

            $table->foreign('business_id')
                ->references('id')
                ->on('businesses')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_capabilities');
    }
};
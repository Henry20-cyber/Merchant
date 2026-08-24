<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('business_id')
                ->constrained('businesses')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->string('name');

            $table->text('description')->nullable();

            $table->decimal('price', 14, 2)
                ->default(0);

            $table->boolean('is_active')
                ->default(true);

            $table->timestamps();

            $table->index([
                'business_id',
                'is_active',
            ]);

            $table->index([
                'business_id',
                'name',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
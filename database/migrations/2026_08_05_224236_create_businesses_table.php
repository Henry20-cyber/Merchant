<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
  public function up(): void
{
    Schema::create('businesses', function (Blueprint $table) {

        $table->uuid('id')->primary();

        $table->foreignUuid('business_type_id')
            ->constrained('business_types')
            ->cascadeOnUpdate()
            ->restrictOnDelete();

        $table->string('name');

        $table->string('slug')->unique();

        $table->string('phone', 20);

        $table->string('email')->nullable();

        $table->string('website')->nullable();

        $table->string('registration_number')->nullable();

        $table->string('tax_number')->nullable();

        $table->string('default_country')->default('Nigeria');

        $table->string('currency', 3)->default('NGN');

        $table->string('timezone')->default('Africa/Lagos');

        $table->string('logo')->nullable();

        $table->string('status')->default('trial');

        $table->timestamps();

        $table->softDeletes();
    });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('businesses');
    }
};

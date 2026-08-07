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
    Schema::create('branches', function (Blueprint $table) {

        $table->uuid('id')->primary();

        $table->foreignUuid('business_id')
            ->constrained()
            ->cascadeOnDelete();

        $table->string('name');

        $table->string('code')->unique();

        $table->string('phone')->nullable();

        $table->string('email')->nullable();

        $table->text('address')->nullable();

        $table->string('city');

        $table->string('state');

        $table->string('country')->default('Nigeria');

        $table->boolean('is_head_office')->default(false);

        $table->timestamps();

        $table->softDeletes();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};

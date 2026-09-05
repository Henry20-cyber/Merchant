<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('business_id')
                ->constrained('businesses')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            /*
             * Parent category.
             *
             * The foreign key is added after the table has
             * been created because this is a self-reference.
             */
            $table->uuid('parent_id')->nullable();

            $table->string('name');

            /*
             * Unique only within a business.
             */
            $table->string('slug');

            $table->text('description')->nullable();

            $table->unsignedInteger('sort_order')
                ->default(0);

            $table->string('status', 20)
                ->default('active');

            $table->timestamps();

            $table->softDeletes();

            /*
             * A business cannot have duplicate category slugs.
             */
            $table->unique([
                'business_id',
                'slug',
            ]);

            /*
             * Useful for category hierarchy queries.
             */
            $table->index([
                'business_id',
                'parent_id',
            ]);

            /*
             * Useful for active/inactive filtering.
             */
            $table->index([
                'business_id',
                'status',
            ]);
        });

        /*
         * Add the self-referencing foreign key only after
         * the categories table and its primary key exist.
         */
        Schema::table('categories', function (Blueprint $table) {
            $table->foreign('parent_id')
                ->references('id')
                ->on('categories')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stocks', function (Blueprint $table) {
            $table->foreignUuid('branch_id')
                ->nullable()
                ->after('business_id')
                ->constrained('branches')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->index(['business_id', 'branch_id']);
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignUuid('branch_id')
                ->nullable()
                ->after('business_id')
                ->constrained('branches')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->index(['business_id', 'branch_id', 'created_at']);
        });

        /*
         * Existing stock records represent the business-level balance
         * that existed before inventory became branch-aware.
         *
         * Where a head office already exists, associate that historical
         * balance with the head office. Records for businesses without a
         * branch remain nullable and are intentionally not guessed.
         */
        DB::statement(<<<'SQL'
            UPDATE stocks AS s
            SET branch_id = b.id
            FROM branches AS b
            WHERE b.business_id = s.business_id
              AND b.is_head_office = TRUE
              AND s.branch_id IS NULL
        SQL);

        DB::statement(<<<'SQL'
            UPDATE stock_movements AS sm
            SET branch_id = s.branch_id
            FROM stocks AS s
            WHERE s.id = sm.stock_id
              AND sm.branch_id IS NULL
        SQL);

        Schema::table('stocks', function (Blueprint $table) {
            $table->dropUnique(['business_id', 'product_id']);

            $table->unique(
                ['business_id', 'branch_id', 'product_id'],
                'stocks_business_branch_product_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('stocks', function (Blueprint $table) {
            $table->dropUnique('stocks_business_branch_product_unique');

            $table->unique(
                ['business_id', 'product_id']
            );
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'branch_id', 'created_at']);
            $table->dropForeign(['branch_id']);
            $table->dropColumn('branch_id');
        });

        Schema::table('stocks', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'branch_id']);
            $table->dropForeign(['branch_id']);
            $table->dropColumn('branch_id');
        });
    }
};

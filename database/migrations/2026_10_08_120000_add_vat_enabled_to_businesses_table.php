<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->boolean('vat_enabled')
                ->default(false)
                ->after('business_scale');
        });

        /*
         * Existing businesses on Medium or Large plans receive the
         * new default. Small plans remain VAT-exempt by default.
         */
        DB::statement(<<<'SQL'
            UPDATE businesses
            SET vat_enabled = true
            WHERE id IN (
                SELECT subscriptions.business_id
                FROM subscriptions
                INNER JOIN subscription_plans
                    ON subscription_plans.id = subscriptions.plan_id
                WHERE subscription_plans.slug LIKE 'medium-%'
                   OR subscription_plans.slug LIKE 'large-%'
            )
        SQL);
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn('vat_enabled');
        });
    }
};

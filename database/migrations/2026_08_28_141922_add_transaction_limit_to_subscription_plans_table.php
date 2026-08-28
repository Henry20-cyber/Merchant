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
        Schema::table('subscription_plans', function (Blueprint $table) {
            /*
             * Maximum number of sales transactions permitted
             * during the plan's usage window.
             *
             * The usage window is currently monthly.
             *
             * Nullable means unlimited.
             */
            $table->unsignedInteger('transaction_limit')
                ->nullable()
                ->after('branch_limit');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropColumn('transaction_limit');
        });
    }
};
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropColumn('transaction_limit');

            $table->unsignedInteger('transaction_daily_limit')
                ->nullable()
                ->after('branch_limit');

            $table->unsignedInteger('transaction_monthly_limit')
                ->nullable()
                ->after('transaction_daily_limit');
        });
    }

    public function down(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropColumn([
                'transaction_daily_limit',
                'transaction_monthly_limit',
            ]);

            $table->unsignedInteger('transaction_limit')
                ->nullable()
                ->after('branch_limit');
        });
    }
};
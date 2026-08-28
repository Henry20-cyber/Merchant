<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestamp('restricted_at')
                ->nullable()
                ->after('grace_period_ends_at');

            $table->index('restricted_at');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex([
                'restricted_at',
            ]);

            $table->dropColumn('restricted_at');
        });
    }
};
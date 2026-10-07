<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->boolean('auto_renew')
                ->default(true)
                ->after('provider_email_token');

            $table->index([
                'business_id',
                'auto_renew',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex([
                'subscriptions_business_id_auto_renew_index',
            ]);

            $table->dropColumn('auto_renew');
        });
    }
};
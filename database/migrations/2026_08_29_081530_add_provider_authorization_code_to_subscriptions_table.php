<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('provider_authorization_code', 255)
                ->nullable()
                ->after('provider_customer_code');

            $table->index('provider_authorization_code');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex([
                'subscriptions_provider_authorization_code_index',
            ]);

            $table->dropColumn('provider_authorization_code');
        });
    }
};
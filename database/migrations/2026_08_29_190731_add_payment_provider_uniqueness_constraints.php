<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * A MerchantOS payment reference identifies exactly
         * one payment.
         *
         * PostgreSQL allows multiple NULL values in a UNIQUE
         * constraint, which is desirable because non-gateway
         * payments may not have a reference.
         */
        Schema::table('payments', function (Blueprint $table) {
            $table->unique(
                'reference',
                'payments_reference_unique'
            );
        });

        /*
         * A provider subscription identifier must uniquely
         * identify a subscription within that provider.
         *
         * This remains provider-scoped so MerchantOS can
         * support multiple payment providers in the future.
         */
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->unique(
                [
                    'provider',
                    'provider_subscription_code',
                ],
                'subscriptions_provider_subscription_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropUnique(
                'subscriptions_provider_subscription_unique'
            );
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(
                'payments_reference_unique'
            );
        });
    }
};
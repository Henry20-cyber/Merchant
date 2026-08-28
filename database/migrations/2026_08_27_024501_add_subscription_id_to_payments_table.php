<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignUuid('subscription_id')
                ->nullable()
                ->after('sale_id')
                ->constrained('subscriptions')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->index([
                'business_id',
                'subscription_id',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign([
                'subscription_id',
            ]);

            $table->dropIndex([
                'payments_business_id_subscription_id_index',
            ]);

            $table->dropColumn('subscription_id');
        });
    }
};
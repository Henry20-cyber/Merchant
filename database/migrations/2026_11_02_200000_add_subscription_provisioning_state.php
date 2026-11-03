<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->unsignedBigInteger('provider_customer_id')->nullable()->after('provider_customer_code');
            $table->string('provisioning_status', 40)->default('not_required')->after('provider_email_token');
            $table->timestamp('provisioning_attempted_at')->nullable()->after('provisioning_status');
            $table->text('provisioning_error')->nullable()->after('provisioning_attempted_at');
            $table->index(['provisioning_status', 'provisioning_attempted_at'], 'subscriptions_provisioning_status_attempted_idx');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex('subscriptions_provisioning_status_attempted_idx');
            $table->dropColumn(['provider_customer_id', 'provisioning_status', 'provisioning_attempted_at', 'provisioning_error']);
        });
    }
};

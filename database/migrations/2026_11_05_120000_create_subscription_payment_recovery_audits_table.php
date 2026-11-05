<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_payment_recovery_audits', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignUuid('payment_id')->constrained('payments')->cascadeOnUpdate()->restrictOnDelete();
            // Keep the actor identifier if the user is later removed.
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('event_type', 80);
            $table->jsonb('event_payload');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['business_id', 'created_at']);
            $table->index(['payment_id', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION prevent_subscription_payment_recovery_audit_mutation()
                RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'subscription payment recovery audits are append-only';
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER subscription_payment_recovery_audits_no_update_delete
                BEFORE UPDATE OR DELETE ON subscription_payment_recovery_audits
                FOR EACH ROW EXECUTE FUNCTION prevent_subscription_payment_recovery_audit_mutation();

                CREATE TRIGGER subscription_payment_recovery_audits_no_truncate
                BEFORE TRUNCATE ON subscription_payment_recovery_audits
                FOR EACH STATEMENT EXECUTE FUNCTION prevent_subscription_payment_recovery_audit_mutation();
            SQL);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('subscription_payment_recovery_audits')
            && DB::table('subscription_payment_recovery_audits')->exists()) {
            throw new RuntimeException('Cannot roll back subscription payment recovery audit history. Preserve the append-only audit trail.');
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS subscription_payment_recovery_audits_no_update_delete ON subscription_payment_recovery_audits');
            DB::unprepared('DROP TRIGGER IF EXISTS subscription_payment_recovery_audits_no_truncate ON subscription_payment_recovery_audits');
            DB::unprepared('DROP FUNCTION IF EXISTS prevent_subscription_payment_recovery_audit_mutation()');
        }
        Schema::dropIfExists('subscription_payment_recovery_audits');
    }
};

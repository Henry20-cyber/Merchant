<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const STATUS_NEXT_ATTEMPT_INDEX = 'payment_webhook_events_status_next_attempt_idx';

    private const STATUS_PROCESSING_STARTED_INDEX = 'payment_webhook_events_status_processing_started_idx';

    private const ATTEMPTS_NONNEGATIVE_CHECK = 'payment_webhook_events_attempts_nonnegative_check';

    public function up(): void
    {
        Schema::table('payment_webhook_events', function (Blueprint $table) {
            $table->uuid('processing_token')->nullable();
            $table->timestamp('processing_started_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();

            $table->index(
                ['status', 'next_attempt_at'],
                self::STATUS_NEXT_ATTEMPT_INDEX,
            );
            $table->index(
                ['status', 'processing_started_at'],
                self::STATUS_PROCESSING_STARTED_INDEX,
            );
        });

        DB::statement(
            'ALTER TABLE payment_webhook_events ADD CONSTRAINT '
            . self::ATTEMPTS_NONNEGATIVE_CHECK
            . ' CHECK (attempts >= 0)'
        );
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE payment_webhook_events DROP CONSTRAINT '
            . self::ATTEMPTS_NONNEGATIVE_CHECK
        );

        Schema::table('payment_webhook_events', function (Blueprint $table) {
            $table->dropIndex(self::STATUS_NEXT_ATTEMPT_INDEX);
            $table->dropIndex(self::STATUS_PROCESSING_STARTED_INDEX);
            $table->dropColumn([
                'processing_token',
                'processing_started_at',
                'attempts',
                'next_attempt_at',
            ]);
        });
    }
};

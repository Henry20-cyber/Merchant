<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_webhook_events', function (Blueprint $table) {
            $table->uuid('id')->primary();

            /*
             * Payment provider.
             *
             * Currently:
             * paystack
             */
            $table->string('provider', 50);

            /*
             * Provider's unique event identifier.
             *
             * This is the primary idempotency key for webhook
             * processing.
             */
            $table->string('provider_event_id', 255);

            /*
             * Event name supplied by the provider.
             *
             * Examples:
             * charge.success
             * invoice.payment_failed
             */
            $table->string('event', 100);

            /*
             * Payment reference supplied by the provider,
             * when available.
             */
            $table->string('reference', 255)
                ->nullable();

            /*
             * Processing state.
             *
             * received
             * processed
             * failed
             */
            $table->string('status', 50)
                ->default('received');

            /*
             * Complete provider payload.
             *
             * JSONB is appropriate for PostgreSQL.
             */
            $table->jsonb('payload');

            /*
             * Error information if processing failed.
             */
            $table->text('error_message')
                ->nullable();

            $table->timestamp('processed_at')
                ->nullable();

            $table->timestamps();

            /*
             * The provider event ID must be unique.
             *
             * This is the database-level protection against
             * duplicate webhook delivery.
             */
            $table->unique([
                'provider',
                'provider_event_id',
            ]);

            $table->index([
                'provider',
                'event',
            ]);

            $table->index('reference');

            $table->index('status');

            $table->index('processed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'payment_webhook_events'
        );
    }
};
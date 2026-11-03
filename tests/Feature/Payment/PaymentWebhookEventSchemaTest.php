<?php

namespace Tests\Feature\Payment;

use App\Domains\Payment\Models\PaymentWebhookEvent;
use Carbon\CarbonInterface;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PaymentWebhookEventSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_processing_columns_have_expected_types_nullability_and_default(): void
    {
        $columns = DB::table('information_schema.columns')
            ->where('table_name', 'payment_webhook_events')
            ->whereRaw('table_schema = current_schema()')
            ->get()
            ->keyBy('column_name');

        expect(Schema::hasColumns('payment_webhook_events', [
            'processing_token',
            'processing_started_at',
            'attempts',
            'next_attempt_at',
        ]))->toBeTrue();

        expect($columns['processing_token']->data_type)->toBe('uuid');
        expect($columns['processing_token']->is_nullable)->toBe('YES');
        expect($columns['processing_started_at']->data_type)->toBe('timestamp without time zone');
        expect($columns['processing_started_at']->is_nullable)->toBe('YES');
        expect($columns['attempts']->data_type)->toBe('integer');
        expect($columns['attempts']->is_nullable)->toBe('NO');
        expect(str_contains((string) $columns['attempts']->column_default, '0'))->toBeTrue();
        expect($columns['next_attempt_at']->data_type)->toBe('timestamp without time zone');
        expect($columns['next_attempt_at']->is_nullable)->toBe('YES');
    }

    public function test_existing_record_and_data_survive_additive_migration(): void
    {
        $migration = require database_path(
            'migrations/2026_11_03_120000_add_processing_fields_to_payment_webhook_events_table.php'
        );
        $migration->down();

        $event = PaymentWebhookEvent::create([
            'provider' => 'paystack',
            'provider_event_id' => 'existing-event-before-extension',
            'event' => 'invoice.payment_failed',
            'reference' => 'REF-EXISTING',
            'status' => 'failed',
            'payload' => ['data' => ['reference' => 'REF-EXISTING']],
            'error_message' => 'Existing failure detail',
        ]);

        $migration->up();

        $event->refresh();
        expect($event->provider)->toBe('paystack');
        expect($event->provider_event_id)->toBe('existing-event-before-extension');
        expect($event->event)->toBe('invoice.payment_failed');
        expect($event->reference)->toBe('REF-EXISTING');
        expect($event->status)->toBe('failed');
        expect($event->payload)->toBe(['data' => ['reference' => 'REF-EXISTING']]);
        expect($event->error_message)->toBe('Existing failure detail');
        expect($event->attempts)->toBe(0);
        expect($event->processing_token)->toBeNull();
        expect($event->processing_started_at)->toBeNull();
        expect($event->next_attempt_at)->toBeNull();
    }

    public function test_new_webhook_record_defaults_attempts_to_zero(): void
    {
        $event = PaymentWebhookEvent::create([
            'provider' => 'paystack',
            'provider_event_id' => 'new-event-default-attempts',
            'event' => 'charge.failed',
            'status' => 'received',
            'payload' => [],
        ]);

        expect($event->fresh()->attempts)->toBe(0);
    }

    public function test_processing_fields_cast_to_expected_types(): void
    {
        $startedAt = Carbon::parse('2026-11-03 10:15:00');
        $nextAttemptAt = Carbon::parse('2026-11-03 10:30:00');
        $event = PaymentWebhookEvent::create([
            'provider' => 'paystack',
            'provider_event_id' => 'cast-fields-event',
            'event' => 'charge.failed',
            'status' => 'failed',
            'payload' => [],
            'processing_token' => 'b630fc35-9b8e-4a12-8cce-fb845c0f128a',
            'processing_started_at' => $startedAt,
            'attempts' => '3',
            'next_attempt_at' => $nextAttemptAt,
        ])->fresh();

        expect($event->processing_token)->toBe('b630fc35-9b8e-4a12-8cce-fb845c0f128a');
        expect($event->processing_started_at)->toBeInstanceOf(CarbonInterface::class);
        expect($event->processing_started_at->equalTo($startedAt))->toBeTrue();
        expect($event->attempts)->toBe(3);
        expect($event->next_attempt_at)->toBeInstanceOf(CarbonInterface::class);
        expect($event->next_attempt_at->equalTo($nextAttemptAt))->toBeTrue();
    }

    public function test_provider_and_event_id_unique_constraint_remains_intact(): void
    {
        $attributes = [
            'provider' => 'paystack',
            'provider_event_id' => 'unique-event-after-extension',
            'event' => 'charge.failed',
            'status' => 'received',
            'payload' => [],
        ];

        PaymentWebhookEvent::create($attributes);

        expect(fn () => DB::transaction(
            fn () => PaymentWebhookEvent::create($attributes)
        ))->toThrow(QueryException::class);

        $this->assertDatabaseCount('payment_webhook_events', 1);
    }

    public function test_negative_attempts_value_is_rejected_by_database(): void
    {
        $event = PaymentWebhookEvent::create([
            'provider' => 'paystack',
            'provider_event_id' => 'negative-attempts-check',
            'event' => 'charge.failed',
            'status' => 'received',
            'payload' => [],
        ]);

        expect(fn () => DB::transaction(
            fn () => DB::table('payment_webhook_events')
                ->where('id', $event->id)
                ->update(['attempts' => -1])
        ))->toThrow(QueryException::class);

        expect($event->fresh()->attempts)->toBe(0);
    }
}

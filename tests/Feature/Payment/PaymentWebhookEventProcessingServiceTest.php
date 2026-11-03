<?php

namespace Tests\Feature\Payment;

use App\Domains\Payment\Models\Payment;
use App\Domains\Payment\Models\PaymentWebhookEvent;
use App\Domains\Payment\Services\PaymentConfirmationService;
use App\Domains\Payment\Services\PaymentWebhookEventProcessingService;
use App\Domains\Payment\Services\SubscriptionPaymentFailureService;
use App\Domains\Payment\Services\SubscriptionRenewalService;
use App\Domains\Subscription\Models\Subscription;
use App\Domains\Subscription\Services\SubscriptionBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PaymentWebhookEventProcessingServiceTest extends TestCase
{
    use RefreshDatabase;

    private function engine(
        ?PaymentConfirmationService $confirmation = null,
        ?SubscriptionRenewalService $renewal = null,
        ?SubscriptionPaymentFailureService $failure = null,
        ?SubscriptionBillingService $billing = null,
    ): PaymentWebhookEventProcessingService {
        return new PaymentWebhookEventProcessingService(
            $confirmation ?? $this->createMock(PaymentConfirmationService::class),
            $renewal ?? $this->createMock(SubscriptionRenewalService::class),
            $failure ?? $this->createMock(SubscriptionPaymentFailureService::class),
            $billing ?? $this->createMock(SubscriptionBillingService::class),
        );
    }

    /** @param array<string, mixed> $payload */
    private function event(array $payload = [], array $attributes = []): PaymentWebhookEvent
    {
        return PaymentWebhookEvent::query()->create(array_merge([
            'provider' => 'paystack',
            'provider_event_id' => (string) Str::uuid(),
            'event' => (string) data_get($payload, 'event', 'unsupported.event'),
            'reference' => data_get($payload, 'data.reference'),
            'status' => 'received',
            'payload' => $payload,
        ], $attributes));
    }

    public function test_first_claim_sets_uuid_token_and_increments_attempt_once(): void
    {
        $observed = null;
        $confirmation = $this->createMock(PaymentConfirmationService::class);

        // Inspect the row while the engine is executing an owned claim.
        $renewal = $this->createMock(SubscriptionRenewalService::class);
        $event = $this->event(['event' => 'charge.success', 'data' => ['reference' => 'REF-1']]);
        $confirmation->expects($this->once())->method('confirm')->with('REF-1')->willReturnCallback(
            function () use ($event, &$observed): Payment {
                $observed = PaymentWebhookEvent::query()->findOrFail($event->id);
                return new Payment();
            }
        );

        $result = $this->engine($confirmation, $renewal)->processById($event->id, allowReceived: true);

        expect($observed->status)->toBe('processing')
            ->and($observed->attempts)->toBe(1)
            ->and($observed->processing_token)->toMatch('/\A[0-9a-f-]{36}\z/i')
            ->and($observed->processing_started_at)->not->toBeNull()
            ->and($result->status)->toBe('processed')
            ->and($result->processing_token)->toBeNull();
    }

    public function test_second_claimant_cannot_acquire_an_active_event(): void
    {
        $event = $this->event(['event' => 'charge.success', 'data' => ['reference' => 'REF-2']]);
        $engine = $this->engine();
        $confirmation = $this->createMock(PaymentConfirmationService::class);
        $confirmation->expects($this->once())->method('confirm')->willReturnCallback(
            function () use ($engine, $event): Payment {
                $during = $engine->processById($event->id);
                expect($during->status)->toBe('processing')
                    ->and($during->attempts)->toBe(1);
                return new Payment();
            }
        );

        $result = $this->engine($confirmation)->processById($event->id, allowReceived: true);

        expect($result->status)->toBe('processed')->and($result->attempts)->toBe(1);
    }

    public function test_lost_completion_ownership_does_not_trigger_failure_transition(): void
    {
        $event = $this->event(['event' => 'charge.success', 'data' => ['reference' => 'REF-OWNERSHIP']]);
        $newToken = (string) Str::uuid();
        $confirmation = $this->createMock(PaymentConfirmationService::class);
        $confirmation->expects($this->once())->method('confirm')->willReturnCallback(
            function () use ($event, $newToken): Payment {
                DB::table('payment_webhook_events')->where('id', $event->id)->update([
                    'status' => 'processing',
                    'processing_token' => $newToken,
                    'error_message' => 'new owner state',
                ]);
                return new Payment();
            }
        );
        $failure = $this->createMock(SubscriptionPaymentFailureService::class);
        $failure->expects($this->never())->method('fail');

        $result = $this->engine($confirmation, failure: $failure)
            ->processById($event->id, allowReceived: true);

        expect($result->status)->toBe('processing')
            ->and($result->processing_token)->toBe($newToken)
            ->and($result->error_message)->toBe('new owner state')
            ->and($result->attempts)->toBe(1);
    }

    public function test_failed_event_is_not_claimed_before_retry_time_and_is_claimed_when_due(): void
    {
        $future = $this->event([], [
            'status' => 'failed',
            'attempts' => 1,
            'next_attempt_at' => now('UTC')->addHour(),
        ]);
        $engine = $this->engine();
        expect($engine->processById($future->id)->attempts)->toBe(1);

        $due = $this->event([], [
            'status' => 'failed',
            'attempts' => 1,
            'next_attempt_at' => now('UTC')->subMinute(),
        ]);
        $result = $engine->processById($due->id);
        expect($result->status)->toBe('processed')->and($result->attempts)->toBe(2);
    }

    public static function failedAttemptProvider(): array
    {
        return [
            'first attempt' => [0, 1, 1],
            'second attempt' => [1, 2, 5],
            'third attempt' => [2, 3, 15],
            'fourth attempt' => [3, 4, 60],
        ];
    }

    #[DataProvider('failedAttemptProvider')]
    public function test_backoff_intervals_match_policy(int $previousAttempts, int $expectedAttempts, int $minutes): void
    {
        $event = $this->event(['event' => 'charge.success', 'data' => []], [
            'status' => $previousAttempts === 0 ? 'received' : 'failed',
            'attempts' => $previousAttempts,
            'next_attempt_at' => $previousAttempts === 0 ? null : now('UTC')->subMinute(),
        ]);
        $before = now('UTC');

        $result = $this->engine()->processById($event->id, allowReceived: true);

        expect($result->status)->toBe('failed')
            ->and($result->attempts)->toBe($expectedAttempts)
            ->and($result->next_attempt_at->betweenIncluded($before->copy()->addMinutes($minutes)->subSeconds(3), $before->copy()->addMinutes($minutes)->addSeconds(3)))->toBeTrue();
    }

    public function test_fifth_failure_moves_to_manual_review_and_attempts_never_exceed_five(): void
    {
        $event = $this->event(['event' => 'charge.success', 'data' => []], [
            'status' => 'failed',
            'attempts' => 4,
            'next_attempt_at' => now('UTC')->subMinute(),
        ]);

        $result = $this->engine()->processById($event->id);

        expect($result->status)->toBe('manual_review')
            ->and($result->attempts)->toBe(5)
            ->and($result->next_attempt_at)->toBeNull();
    }

    public function test_expired_lease_is_reclaimed_with_a_new_token(): void
    {
        $event = $this->event(['event' => 'charge.success', 'data' => ['reference' => 'REF-LEASE']], [
            'status' => 'processing',
            'attempts' => 1,
            'processing_token' => (string) Str::uuid(),
            'processing_started_at' => now('UTC')->subMinutes(11),
        ]);
        $oldToken = $event->processing_token;
        $newToken = null;
        $confirmation = $this->createMock(PaymentConfirmationService::class);
        $confirmation->expects($this->once())->method('confirm')->willReturnCallback(
            function () use ($event, &$newToken): Payment {
                $newToken = $event->fresh()->processing_token;
                return new Payment();
            }
        );

        $result = $this->engine($confirmation)->processById($event->id);

        expect($result->status)->toBe('processed')
            ->and($result->attempts)->toBe(2)
            ->and($newToken)->not->toBe($oldToken)
            ->and($newToken)->not->toBeNull();
    }

    public function test_former_token_cannot_complete_after_reclamation(): void
    {
        $event = $this->event([], [
            'status' => 'processing',
            'attempts' => 2,
            'processing_token' => (string) Str::uuid(),
            'processing_started_at' => now('UTC'),
        ]);
        $formerToken = $event->processing_token;
        $newToken = (string) Str::uuid();
        DB::table('payment_webhook_events')->where('id', $event->id)->update([
            'processing_token' => $newToken,
            'error_message' => 'newer worker audit',
            'next_attempt_at' => now('UTC')->addMinutes(5),
        ]);

        expect($this->engine()->complete($event->id, $formerToken))->toBeFalse();

        $result = $event->fresh();
        expect($result->status)->toBe('processing')
            ->and($result->attempts)->toBe(2)
            ->and($result->processing_token)->toBe($newToken)
            ->and($result->error_message)->toBe('newer worker audit')
            ->and($result->next_attempt_at)->not->toBeNull();
    }

    public function test_former_token_cannot_fail_or_overwrite_newer_retry_state(): void
    {
        $event = $this->event([], [
            'status' => 'processing',
            'attempts' => 3,
            'processing_token' => (string) Str::uuid(),
            'processing_started_at' => now('UTC'),
        ]);
        $formerToken = $event->processing_token;
        $newToken = (string) Str::uuid();
        DB::table('payment_webhook_events')->where('id', $event->id)->update([
            'processing_token' => $newToken,
            'error_message' => 'newer worker error',
            'next_attempt_at' => now('UTC')->addMinutes(15),
        ]);

        expect($this->engine()->fail($event->id, $formerToken, 'stale worker error'))->toBeFalse();

        $result = $event->fresh();
        expect($result->status)->toBe('processing')
            ->and($result->attempts)->toBe(3)
            ->and($result->processing_token)->toBe($newToken)
            ->and($result->error_message)->toBe('newer worker error')
            ->and($result->next_attempt_at)->not->toBeNull();
    }

    public function test_expired_fifth_attempt_moves_to_manual_review(): void
    {
        $event = $this->event([], [
            'status' => 'processing',
            'attempts' => 5,
            'processing_token' => (string) Str::uuid(),
            'processing_started_at' => now('UTC')->subMinutes(11),
        ]);

        $result = $this->engine()->processById($event->id);

        expect($result->status)->toBe('manual_review')->and($result->attempts)->toBe(5);
    }

    public function test_legacy_failed_event_moves_to_manual_review_without_losing_audit_data(): void
    {
        $payload = ['event' => 'charge.failed', 'data' => ['reference' => 'LEGACY-REF']];
        $event = $this->event($payload, [
            'status' => 'failed',
            'attempts' => 0,
            'next_attempt_at' => null,
            'error_message' => 'historic reason',
        ]);

        $this->engine()->processDue();

        $result = $event->fresh();
        expect($result->status)->toBe('manual_review')
            ->and($result->payload)->toEqual($payload)
            ->and($result->reference)->toBe('LEGACY-REF')
            ->and($result->provider_event_id)->toBe($event->provider_event_id)
            ->and($result->error_message)->toBe('historic reason');
    }

    public function test_processed_and_manual_review_events_are_not_claimed(): void
    {
        foreach (['processed', 'manual_review'] as $status) {
            $event = $this->event([], ['status' => $status, 'attempts' => 1]);
            $result = $this->engine()->processById($event->id, allowReceived: true);
            expect($result->status)->toBe($status)->and($result->attempts)->toBe(1);
        }
    }

    public function test_newer_delivery_cannot_duplicate_payment_confirmation(): void
    {
        $payload = ['event' => 'charge.success', 'data' => ['reference' => 'ONE-TIME-REF']];
        $confirmation = $this->createMock(PaymentConfirmationService::class);
        $confirmation->expects($this->once())->method('confirm')->with('ONE-TIME-REF')
            ->willReturn(new Payment());
        $engine = $this->engine($confirmation);

        $first = $engine->recordAndProcess($payload, 'same-provider-event');
        $second = $engine->recordAndProcess($payload, 'same-provider-event');

        expect($first->id)->toBe($second->id)
            ->and($second->status)->toBe('processed')
            ->and($second->attempts)->toBe(1);
    }

    public function test_duplicate_recurring_delivery_does_not_repeat_subscription_renewal(): void
    {
        $payload = [
            'event' => 'charge.success',
            'data' => [
                'reference' => 'RENEWAL-REF',
                'subscription' => ['subscription_code' => 'SUB-CODE'],
                'customer' => ['customer_code' => 'CUS-CODE'],
            ],
        ];
        $renewal = $this->createMock(SubscriptionRenewalService::class);
        $renewal->expects($this->once())->method('renew')->with([
            'reference' => 'RENEWAL-REF',
            'customer_code' => 'CUS-CODE',
            'subscription_code' => 'SUB-CODE',
            'amount' => null,
            'currency' => null,
            'paid_at' => null,
        ])->willReturn(new Payment());
        $engine = $this->engine(renewal: $renewal);

        $first = $engine->recordAndProcess($payload, 'same-renewal-event');
        $second = $engine->recordAndProcess($payload, 'same-renewal-event');

        expect($first->id)->toBe($second->id)
            ->and($second->status)->toBe('processed')
            ->and($second->attempts)->toBe(1);
    }

    public function test_invoice_payment_failed_dispatches_exact_failure_identifiers(): void
    {
        $payload = [
            'event' => 'invoice.payment_failed',
            'data' => [
                'customer' => ['customer_code' => 'CUS-FAIL'],
                'subscription' => ['subscription_code' => 'SUB-FAIL'],
                'transaction' => [
                    'reference' => 'FAIL-REF',
                    'amount' => 12500,
                    'currency' => 'NGN',
                ],
            ],
        ];
        $failure = $this->createMock(SubscriptionPaymentFailureService::class);
        $failure->expects($this->once())->method('fail')->with([
            'customer_code' => 'CUS-FAIL',
            'subscription_code' => 'SUB-FAIL',
            'reference' => 'FAIL-REF',
            'amount' => 12500,
            'currency' => 'NGN',
        ])->willReturn(new Subscription());

        $result = $this->engine(failure: $failure)
            ->recordAndProcess($payload, 'invoice-failure-dispatch');

        expect($result->status)->toBe('processed');
    }

    public function test_subscription_not_renew_dispatches_to_non_renewing_handler(): void
    {
        $billing = $this->createMock(SubscriptionBillingService::class);
        $billing->expects($this->once())->method('markNonRenewing')
            ->with('paystack', 'SUB-NON-RENEW')->willReturn(null);

        $result = $this->engine(billing: $billing)->recordAndProcess([
            'event' => 'subscription.not_renew',
            'data' => ['subscription_code' => 'SUB-NON-RENEW'],
        ], 'subscription-not-renew-dispatch');

        expect($result->status)->toBe('processed');
    }

    public function test_subscription_disable_dispatches_to_disabled_handler(): void
    {
        $billing = $this->createMock(SubscriptionBillingService::class);
        $billing->expects($this->once())->method('markDisabled')
            ->with('paystack', 'SUB-DISABLED')->willReturn(null);

        $result = $this->engine(billing: $billing)->recordAndProcess([
            'event' => 'subscription.disable',
            'data' => ['subscription' => ['subscription_code' => 'SUB-DISABLED']],
        ], 'subscription-disable-dispatch');

        expect($result->status)->toBe('processed');
    }

    public function test_charge_failed_is_acknowledged_without_subscription_mutation(): void
    {
        $failure = $this->createMock(SubscriptionPaymentFailureService::class);
        $failure->expects($this->never())->method('fail');
        $billing = $this->createMock(SubscriptionBillingService::class);
        $billing->expects($this->never())->method('markNonRenewing');
        $billing->expects($this->never())->method('markDisabled');

        $result = $this->engine(failure: $failure, billing: $billing)->recordAndProcess([
            'event' => 'charge.failed',
            'data' => ['reference' => 'CHARGE-FAILED-REF'],
        ], 'charge-failed-no-mutation');

        expect($result->status)->toBe('processed')->and($result->attempts)->toBe(1);
    }

    public function test_missing_required_identifiers_keep_controller_no_op_behavior(): void
    {
        $failure = $this->createMock(SubscriptionPaymentFailureService::class);
        $failure->expects($this->never())->method('fail');
        $billing = $this->createMock(SubscriptionBillingService::class);
        $billing->expects($this->never())->method('markNonRenewing');
        $billing->expects($this->never())->method('markDisabled');
        $engine = $this->engine(failure: $failure, billing: $billing);

        $invoice = $engine->recordAndProcess([
            'event' => 'invoice.payment_failed',
            'data' => ['customer' => ['customer_code' => 'CUS-ONLY']],
        ], 'invoice-failure-missing-subscription');
        $notRenewing = $engine->recordAndProcess([
            'event' => 'subscription.not_renew',
            'data' => [],
        ], 'subscription-not-renew-missing-code');
        $disabled = $engine->recordAndProcess([
            'event' => 'subscription.disable',
            'data' => [],
        ], 'subscription-disable-missing-code');

        expect($invoice->status)->toBe('processed')
            ->and($notRenewing->status)->toBe('processed')
            ->and($disabled->status)->toBe('processed');
    }

    public function test_synchronized_postgresql_claims_on_independent_connections_only_increment_once(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires PostgreSQL for independent concurrent claims.');
        }

        $originalDefault = DB::getDefaultConnection();
        $connectionName = 'webhook_race_' . bin2hex(random_bytes(4));
        $connectionConfig = config("database.connections.{$originalDefault}");
        if (($connectionConfig['database'] ?? null) !== 'account_payment_test'
            || (string) ($connectionConfig['port'] ?? '') !== '5433') {
            $this->fail('Concurrency test must use the isolated account_payment_test database on port 5433.');
        }

        config(["database.connections.{$connectionName}" => $connectionConfig]);
        DB::purge($connectionName);
        $raceConnection = null;
        $eventId = (string) Str::uuid();
        $startFile = null;
        $readyFile = null;
        $process = null;
        $event = null;
        $childOutput = '';
        $childError = '';
        $exitCode = null;

        try {
            $raceConnection = DB::connection($connectionName);
            $raceConnection->table('payment_webhook_events')->insert([
                'id' => $eventId,
                'provider' => 'paystack',
                'provider_event_id' => 'concurrency-' . $eventId,
                'event' => 'unsupported.event',
                'status' => 'received',
                'payload' => json_encode(['event' => 'unsupported.event'], JSON_THROW_ON_ERROR),
                'attempts' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $startFile = tempnam(sys_get_temp_dir(), 'webhook-start-');
            $readyFile = tempnam(sys_get_temp_dir(), 'webhook-ready-');
            if ($startFile === false || $readyFile === false) {
                throw new \RuntimeException('Unable to create concurrency barrier files.');
            }
            @unlink($startFile);
            @unlink($readyFile);

            $workerCode = <<<'PHP'
                require getcwd().'/vendor/autoload.php';

                try {
                    $input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
                    $app = require getcwd().'/bootstrap/app.php';
                    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

                    config([
                        'database.default' => 'pgsql',
                        'database.connections.pgsql' => array_replace(
                            config('database.connections.pgsql'),
                            $input['database_config'],
                        ),
                        'services.paystack.secret_key' => 'sk_test_webhook_concurrency_placeholder',
                    ]);
                    \Illuminate\Support\Facades\DB::purge('pgsql');
                    \Illuminate\Support\Facades\DB::setDefaultConnection('pgsql');

                    $connection = \Illuminate\Support\Facades\DB::connection('pgsql');
                    $identity = $connection->selectOne('SELECT current_database() AS database_name, inet_server_port() AS server_port');
                    if ($identity->database_name !== $input['expected_database']
                        || (int) $identity->server_port !== (int) $input['expected_port']) {
                        throw new \RuntimeException('Worker connected to an unexpected test database.');
                    }

                    if (file_put_contents($input['ready_file'], 'ready') === false) {
                        throw new \RuntimeException('Unable to signal concurrency worker readiness.');
                    }

                    $deadline = microtime(true) + 15;
                    while (! is_file($input['start_file']) && microtime(true) < $deadline) {
                        usleep(1000);
                    }
                    if (! is_file($input['start_file'])) {
                        throw new \RuntimeException('Timed out waiting for synchronized claim start.');
                    }

                    $event = app(\App\Domains\Payment\Services\PaymentWebhookEventProcessingService::class)
                        ->processById($input['event_id'], allowReceived: true);
                    echo json_encode([
                        'status' => $event->status,
                        'attempts' => $event->attempts,
                    ], JSON_THROW_ON_ERROR).PHP_EOL;
                } catch (\Throwable $exception) {
                    fwrite(STDERR, get_class($exception).': '.$exception->getMessage().PHP_EOL);
                    exit(1);
                }
                PHP;

            $databaseConfig = array_intersect_key($connectionConfig, array_flip([
                'driver', 'host', 'port', 'database', 'username', 'password',
                'charset', 'prefix', 'prefix_indexes', 'search_path', 'sslmode',
            ]));
            unset($databaseConfig['url']);
            $databaseConfig['url'] = null;
            $processInput = json_encode([
                'database_config' => $databaseConfig,
                'expected_database' => 'account_payment_test',
                'expected_port' => 5433,
                'event_id' => $eventId,
                'ready_file' => $readyFile,
                'start_file' => $startFile,
            ], JSON_THROW_ON_ERROR);

            $process = new Process(
                [PHP_BINARY, '-r', $workerCode],
                base_path(),
                ['APP_ENV' => 'testing'],
                $processInput,
                30,
            );
            $process->start();

            $readyDeadline = microtime(true) + 20;
            while (! is_file($readyFile) && $process->isRunning() && microtime(true) < $readyDeadline) {
                $process->checkTimeout();
                usleep(1000);
            }
            if (! is_file($readyFile)) {
                $childOutput = $process->getOutput();
                $childError = $process->getErrorOutput();
                throw new \RuntimeException(sprintf(
                    'Concurrency worker did not become ready (exit=%s; stdout=%s; stderr=%s).',
                    var_export($process->getExitCode(), true),
                    trim($childOutput),
                    trim($childError),
                ));
            }

            // Both independent PostgreSQL connections are ready before the shared release.
            touch($startFile);
            DB::setDefaultConnection($connectionName);
            $this->engine()->processById($eventId, allowReceived: true);

            $exitCode = $process->wait();
            $childOutput = $process->getOutput();
            $childError = $process->getErrorOutput();
            $event = $raceConnection->table('payment_webhook_events')->where('id', $eventId)->first();
        } catch (\Throwable $exception) {
            $childOutput = $process instanceof Process ? $process->getOutput() : $childOutput;
            $childError = $process instanceof Process ? $process->getErrorOutput() : $childError;
            throw new \RuntimeException(sprintf(
                '%s Child stdout=%s; stderr=%s',
                $exception->getMessage(),
                trim($childOutput),
                trim($childError),
            ), previous: $exception);
        } finally {
            // Release a waiting worker, then stop/reap it before deleting its shared event.
            if (is_string($startFile)) {
                @touch($startFile);
            }
            try {
                if ($process instanceof Process && $process->isRunning()) {
                    $process->stop(0.5);
                }
            } finally {
                DB::setDefaultConnection($originalDefault);
                try {
                    $raceConnection?->table('payment_webhook_events')->where('id', $eventId)->delete();
                } finally {
                    DB::purge($connectionName);
                    if (is_string($startFile)) {
                        @unlink($startFile);
                    }
                    if (is_string($readyFile)) {
                        @unlink($readyFile);
                    }
                }
            }
        }

        $childDiagnostic = sprintf(
            'Concurrent child exit=%s; stdout=%s; stderr=%s',
            var_export($exitCode, true),
            trim($childOutput),
            trim($childError),
        );
        $this->assertSame(0, $exitCode, $childDiagnostic);
        $childResult = json_decode(trim($childOutput), true);
        $this->assertIsArray($childResult, $childDiagnostic);
        $this->assertSame(1, $childResult['attempts'] ?? null, $childDiagnostic);
        $this->assertSame('processed', $childResult['status'] ?? null, $childDiagnostic);
        $this->assertSame(1, $event->attempts);
        $this->assertSame('processed', $event->status);
    }
}

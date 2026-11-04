<?php

namespace Tests\Feature\Payment;

use App\Domains\Organization\Models\Business;
use App\Domains\Payment\Contracts\PaymentGateway;
use App\Domains\Payment\Models\Payment;
use App\Domains\Payment\Models\SubscriptionCheckoutRecovery;
use App\Domains\Payment\Services\PaystackSubscriptionProvisioningService;
use App\Domains\Subscription\Models\Subscription;
use App\Domains\Subscription\Models\SubscriptionPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class SubscriptionCheckoutReconciliationCommandTest extends TestCase
{
    use RefreshDatabase;

    private PaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = Mockery::mock(PaymentGateway::class);
        $this->app->instance(PaymentGateway::class, $this->gateway);
    }

    public function test_inconclusive_provider_response_keeps_payment_pending_and_applies_first_backoff(): void
    {
        $now = now()->startOfSecond();
        $this->travelTo($now);
        [$payment, $recovery] = $this->createAttempt(createdAt: $now->copy()->subMinutes(10));
        $this->gateway->shouldReceive('verify')->once()->with($payment->reference)
            ->andReturn($this->verification($payment, 'pending'));

        Artisan::call('payments:reconcile-subscription-checkouts', ['--limit' => 50]);

        self::assertSame('pending', $payment->fresh()->status);
        self::assertSame(1, $payment->fresh()->reconciliation_attempts);
        self::assertTrue($payment->fresh()->next_reconciliation_at->equalTo($now->copy()->addMinutes(5)));
        self::assertSame('pending', $recovery->fresh()->status);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('verificationErrors')]
    public function test_provider_errors_and_timeouts_leave_payment_pending(string $message): void
    {
        [$payment, $recovery] = $this->createAttempt(createdAt: now()->subMinutes(10));
        $this->gateway->shouldReceive('verify')->once()->with($payment->reference)
            ->andThrow(new RuntimeException($message));

        Artisan::call('payments:reconcile-subscription-checkouts', ['--limit' => 50]);

        self::assertSame('pending', $payment->fresh()->status);
        self::assertSame(1, $payment->fresh()->reconciliation_attempts);
        self::assertNotNull($payment->fresh()->next_reconciliation_at);
        self::assertSame('pending', $recovery->fresh()->status);
    }

    public static function verificationErrors(): array
    {
        return [
            'provider API error' => ['Paystack verification API unavailable.'],
            'network timeout' => ['Paystack verification timed out.'],
        ];
    }

    public function test_definitive_failure_marks_only_payment_failed_and_makes_recovery_retryable(): void
    {
        [$payment, $recovery, $subscription, $freePlan] = $this->createAttempt(
            withFreeSubscription: true,
            createdAt: now()->subMinutes(10),
        );
        $this->gateway->shouldReceive('verify')->once()->with($payment->reference)
            ->andReturn($this->verification($payment, 'failed'));
        $provisioner = Mockery::mock(PaystackSubscriptionProvisioningService::class);
        $provisioner->shouldNotReceive('provision');
        $this->app->instance(PaystackSubscriptionProvisioningService::class, $provisioner);

        Artisan::call('payments:reconcile-subscription-checkouts', ['--limit' => 50]);

        self::assertSame('failed', $payment->fresh()->status);
        self::assertSame('retryable', $recovery->fresh()->status);
        self::assertSame('active', $subscription->fresh()->status);
        self::assertSame($freePlan->id, $subscription->fresh()->plan_id);
        self::assertNull($payment->fresh()->paid_at);
    }

    public function test_definitive_success_marks_payment_paid_and_provisions_the_exact_subscription(): void
    {
        [$payment, $recovery, $subscription] = $this->createAttempt(
            withTrialSubscription: true,
            createdAt: now()->subMinutes(10),
        );
        $this->gateway->shouldReceive('verify')->once()->with($payment->reference)
            ->andReturn($this->verification($payment, 'success'));
        $provisioner = Mockery::mock(PaystackSubscriptionProvisioningService::class);
        $provisioner->shouldReceive('provision')->once()->with(Mockery::on(
            fn (Subscription $value): bool => (string) $value->id === (string) $subscription->id,
        ))->andReturnUsing(fn (Subscription $value): Subscription => $value);
        $this->app->instance(PaystackSubscriptionProvisioningService::class, $provisioner);

        Artisan::call('payments:reconcile-subscription-checkouts', ['--limit' => 50]);

        self::assertSame('paid', $payment->fresh()->status);
        self::assertNotNull($payment->fresh()->paid_at);
        self::assertSame('succeeded', $recovery->fresh()->status);
        self::assertSame($payment->id, $recovery->fresh()->winning_payment_id);
        self::assertSame('active', $subscription->fresh()->status);
    }

    public function test_already_paid_payment_is_not_selected_or_verified(): void
    {
        [$payment] = $this->createAttempt(createdAt: now()->subMinutes(10));
        $payment->forceFill(['status' => 'paid', 'paid_at' => now()])->save();
        $this->gateway->shouldNotReceive('verify');

        Artisan::call('payments:reconcile-subscription-checkouts', ['--limit' => 50]);

        self::assertSame('paid', $payment->fresh()->status);
        self::assertSame(0, $payment->fresh()->reconciliation_attempts);
    }

    public function test_payment_is_not_selected_before_its_next_reconciliation_time(): void
    {
        [$payment] = $this->createAttempt(
            createdAt: now()->subMinutes(10),
            nextReconciliationAt: now()->addMinute(),
        );
        $this->gateway->shouldNotReceive('verify');

        Artisan::call('payments:reconcile-subscription-checkouts', ['--limit' => 50]);

        self::assertSame('pending', $payment->fresh()->status);
        self::assertSame(0, $payment->fresh()->reconciliation_attempts);
    }

    public function test_payment_that_becomes_ineligible_before_workers_are_released_is_not_reconciled(): void
    {
        $fixture = $this->createCommittedAttempt();

        try {
            $result = $this->runConcurrentWorkers($fixture, 1, markPaidBeforeRelease: true);
            $payment = DB::connection($fixture['connection_name'])
                ->table('payments')->where('id', $fixture['payment_id'])->first();

            self::assertSame([], $result['provider_calls']);
            self::assertSame('paid', $payment->status);
            self::assertSame(0, (int) $payment->reconciliation_attempts);
            self::assertNull($payment->next_reconciliation_at);
        } finally {
            $this->deleteCommittedFixture($fixture);
        }
    }

    public function test_concurrent_command_runs_claim_one_payment_once(): void
    {
        $fixture = $this->createCommittedAttempt();

        try {
            $result = $this->runConcurrentWorkers($fixture, 2);
            $payment = DB::connection($fixture['connection_name'])
                ->table('payments')->where('id', $fixture['payment_id'])->first();

            self::assertCount(2, $result['results']);
            self::assertCount(1, $result['provider_calls']);
            self::assertSame(1, (int) $payment->reconciliation_attempts);
            self::assertSame('pending', $payment->status);
            self::assertNotNull($payment->next_reconciliation_at);
        } finally {
            $this->deleteCommittedFixture($fixture);
        }
    }

    public function test_five_minute_age_boundary_is_inclusive_and_younger_payment_is_not_selected(): void
    {
        $now = now()->startOfSecond();
        $this->travelTo($now);
        [$eligible] = $this->createAttempt(createdAt: $now->copy()->subMinutes(5));
        [$tooYoung] = $this->createAttempt(createdAt: $now->copy()->subMinutes(5)->addSecond());
        $this->gateway->shouldReceive('verify')->once()->with($eligible->reference)
            ->andReturn($this->verification($eligible, 'pending'));

        Artisan::call('payments:reconcile-subscription-checkouts', ['--limit' => 50]);

        self::assertSame(1, $eligible->fresh()->reconciliation_attempts);
        self::assertSame(0, $tooYoung->fresh()->reconciliation_attempts);
    }

    public function test_batch_limit_is_respected(): void
    {
        $payments = [];
        for ($index = 0; $index < 3; $index++) {
            [$payments[]] = $this->createAttempt(createdAt: now()->subMinutes(10));
        }
        $this->gateway->shouldReceive('verify')->twice()->andReturnUsing(
            fn (string $reference): array => $this->verification(
                collect($payments)->firstWhere('reference', $reference),
                'pending',
            ),
        );

        Artisan::call('payments:reconcile-subscription-checkouts', ['--limit' => 2]);

        self::assertSame(2, collect($payments)->filter(fn (Payment $payment): bool => $payment->fresh()->reconciliation_attempts === 1)->count());
        self::assertSame(1, collect($payments)->filter(fn (Payment $payment): bool => $payment->fresh()->reconciliation_attempts === 0)->count());
    }

    public function test_second_retry_uses_fifteen_minute_backoff(): void
    {
        $now = now()->startOfSecond();
        $this->travelTo($now);
        [$payment] = $this->createAttempt(createdAt: $now->copy()->subHour());
        $this->gateway->shouldReceive('verify')->twice()->with($payment->reference)
            ->andReturn($this->verification($payment, 'pending'));

        Artisan::call('payments:reconcile-subscription-checkouts', ['--limit' => 50]);
        self::assertTrue($payment->fresh()->next_reconciliation_at->equalTo($now->copy()->addMinutes(5)));

        $this->travelTo($now->copy()->addMinutes(5));
        Artisan::call('payments:reconcile-subscription-checkouts', ['--limit' => 50]);

        self::assertSame(2, $payment->fresh()->reconciliation_attempts);
        self::assertTrue($payment->fresh()->next_reconciliation_at->equalTo($now->copy()->addMinutes(20)));
    }

    public function test_eighth_inconclusive_attempt_moves_recovery_to_manual_review_without_entitlement(): void
    {
        [$payment, $recovery, $subscription, $freePlan] = $this->createAttempt(
            withFreeSubscription: true,
            createdAt: now()->subDays(2),
            reconciliationAttempts: 7,
            nextReconciliationAt: now()->subMinute(),
        );
        $this->gateway->shouldReceive('verify')->once()->with($payment->reference)
            ->andReturn($this->verification($payment, 'pending'));
        $provisioner = Mockery::mock(PaystackSubscriptionProvisioningService::class);
        $provisioner->shouldNotReceive('provision');
        $this->app->instance(PaystackSubscriptionProvisioningService::class, $provisioner);

        Artisan::call('payments:reconcile-subscription-checkouts', ['--limit' => 50]);

        self::assertSame('pending', $payment->fresh()->status);
        self::assertSame(8, $payment->fresh()->reconciliation_attempts);
        self::assertSame('manual_review', $recovery->fresh()->status);
        self::assertSame('active', $subscription->fresh()->status);
        self::assertSame($freePlan->id, $subscription->fresh()->plan_id);
        self::assertNull($payment->fresh()->paid_at);
    }

    public function test_eighth_attempt_is_the_maximum_claimed_attempt(): void
    {
        [$payment] = $this->createAttempt(
            createdAt: now()->subDays(2),
            reconciliationAttempts: 8,
            nextReconciliationAt: now()->subMinute(),
        );
        $this->gateway->shouldNotReceive('verify');

        Artisan::call('payments:reconcile-subscription-checkouts', ['--limit' => 50]);

        self::assertSame(8, $payment->fresh()->reconciliation_attempts);
    }

    private function createAttempt(
        bool $withFreeSubscription = false,
        bool $withTrialSubscription = false,
        ?\Carbon\CarbonInterface $createdAt = null,
        int $reconciliationAttempts = 0,
        ?\Carbon\CarbonInterface $nextReconciliationAt = null,
    ): array {
        $business = Business::factory()->create();
        $plan = SubscriptionPlan::factory()->create([
            'slug' => 'reconcile-' . strtolower(bin2hex(random_bytes(5))),
            'price' => '3000.00',
            'currency' => 'NGN',
            'billing_interval' => 'monthly',
            'paystack_plan_code' => 'PLN_reconcile_' . bin2hex(random_bytes(5)),
            'is_active' => true,
        ]);
        $freePlan = null;
        $subscription = null;
        if ($withFreeSubscription) {
            $freePlan = SubscriptionPlan::factory()->create([
                'slug' => 'free', 'price' => '0.00', 'paystack_plan_code' => null,
            ]);
            $subscription = Subscription::factory()->create([
                'business_id' => $business->id, 'plan_id' => $freePlan->id, 'status' => 'active',
            ]);
        } elseif ($withTrialSubscription) {
            $subscription = Subscription::factory()->create([
                'business_id' => $business->id, 'plan_id' => $plan->id, 'status' => 'trial',
            ]);
        }

        $terms = [
            'plan_id' => $plan->id,
            'plan_slug' => $plan->slug,
            'amount' => (string) $plan->price,
            'currency' => $plan->currency,
            'billing_interval' => $plan->billing_interval,
            'paystack_plan_code' => $plan->paystack_plan_code,
            'subscription_id' => $subscription?->id,
        ];
        $recovery = SubscriptionCheckoutRecovery::query()->create([
            'business_id' => $business->id,
            'subscription_id' => $subscription?->id,
            'plan_id' => $plan->id,
            'purchase_terms' => $terms,
            'status' => 'pending',
        ]);
        $reference = 'MERCHANTOS-RECONCILE-' . strtoupper(bin2hex(random_bytes(6)));
        $payment = Payment::factory()->create([
            'business_id' => $business->id,
            'subscription_id' => $subscription?->id,
            'sale_id' => null,
            'amount' => $plan->price,
            'method' => 'paystack',
            'status' => 'pending',
            'reference' => $reference,
            'checkout_recovery_id' => $recovery->id,
            'attempt_number' => 1,
            'reconciliation_attempts' => $reconciliationAttempts,
            'next_reconciliation_at' => $nextReconciliationAt,
            'created_at' => $createdAt ?? now()->subMinutes(10),
            'paid_at' => null,
            'metadata' => [
                'type' => 'subscription',
                'business_id' => $business->id,
                'subscription_id' => $subscription?->id,
                'plan_id' => $plan->id,
                'plan_slug' => $plan->slug,
                'billing_interval' => $plan->billing_interval,
                'currency' => $plan->currency,
                'paystack_plan_code' => $plan->paystack_plan_code,
            ],
        ]);

        return [$payment, $recovery, $subscription, $freePlan, $business, $plan];
    }

    private function verification(Payment $payment, string $status): array
    {
        return [
            'success' => true,
            'status' => $status,
            'reference' => $payment->reference,
            'amount' => 300000,
            'currency' => 'NGN',
            'authorization_code' => $status === 'success' ? 'AUTH_RECONCILE' : null,
            'customer_code' => $status === 'success' ? 'CUS_RECONCILE' : null,
        ];
    }

    private function createCommittedAttempt(): array
    {
        $connectionName = 'payment_reconcile_race_' . bin2hex(random_bytes(4));
        $connectionConfig = config('database.connections.pgsql');
        config(["database.connections.{$connectionName}" => $connectionConfig]);
        DB::purge($connectionName);
        $originalConnection = DB::getDefaultConnection();
        DB::setDefaultConnection($connectionName);

        try {
            [$payment, $recovery, , , $business, $plan] = $this->createAttempt(createdAt: now()->subMinutes(10));

            return [
                'connection_name' => $connectionName,
                'connection_config' => $connectionConfig,
                'business_id' => $business->id,
                'business_type_id' => $business->business_type_id,
                'plan_id' => $plan->id,
                'payment_id' => $payment->id,
                'payment_reference' => $payment->reference,
                'recovery_id' => $recovery->id,
            ];
        } finally {
            DB::setDefaultConnection($originalConnection);
        }
    }

    private function deleteCommittedFixture(array $fixture): void
    {
        $connection = DB::connection($fixture['connection_name']);
        $connection->table('payments')->where('business_id', $fixture['business_id'])->delete();
        $connection->table('subscription_checkout_recoveries')->where('business_id', $fixture['business_id'])->delete();
        $connection->table('subscriptions')->where('business_id', $fixture['business_id'])->delete();
        $connection->table('businesses')->where('id', $fixture['business_id'])->delete();
        $connection->table('subscription_plans')->where('id', $fixture['plan_id'])->delete();
        $connection->table('business_types')->where('id', $fixture['business_type_id'])->delete();
        DB::purge($fixture['connection_name']);
    }

    private function runConcurrentWorkers(array $fixture, int $workerCount, bool $markPaidBeforeRelease = false): array
    {
        $directory = sys_get_temp_dir() . '/account-reconcile-race-' . bin2hex(random_bytes(6));
        if (! mkdir($directory, 0700)) {
            throw new RuntimeException('Unable to create reconciliation worker directory.');
        }

        $launchFile = $directory . '/launch';
        $providerLog = $directory . '/provider-calls.log';
        $gatePrefix = 'account-reconcile-' . bin2hex(random_bytes(4));
        file_put_contents($providerLog, '');

        $connection = DB::connection($fixture['connection_name']);
        $processes = [];
        try {
            $connectionConfig = array_intersect_key($fixture['connection_config'], array_flip([
                'driver', 'host', 'port', 'database', 'username', 'password', 'charset',
                'prefix', 'prefix_indexes', 'search_path', 'sslmode',
            ]));
            $connectionConfig['url'] = null;
            $workerCode = $this->reconciliationWorkerCode();

            for ($workerId = 0; $workerId < $workerCount; $workerId++) {
                $input = [
                    'database_config' => $connectionConfig,
                    'expected_database' => 'account_concurrency_test',
                    'expected_host' => '127.0.0.1',
                    'expected_port' => 5432,
                    'expected_role' => 'postgres',
                    'payment_reference' => $fixture['payment_reference'],
                    'ready_file' => $directory . '/ready-' . $workerId,
                    'launch_file' => $launchFile,
                    'provider_log' => $providerLog,
                ];
                $process = new Process(
                    [PHP_BINARY, '-r', $workerCode],
                    base_path(),
                    ['APP_ENV' => 'testing', 'PGAPPNAME' => $gatePrefix . '-' . $workerId],
                    json_encode($input, JSON_THROW_ON_ERROR),
                    60,
                );
                $process->start();
                $processes[] = $process;
            }

            $readyDeadline = microtime(true) + 30;
            while (! $this->allReady($directory, $workerCount) && microtime(true) < $readyDeadline) {
                $this->assertWorkersRunning($processes);
                usleep(1000);
            }
            self::assertTrue($this->allReady($directory, $workerCount), 'Reconciliation workers did not connect to the approved test database.');
            if ($markPaidBeforeRelease) {
                $connection->table('payments')->where('id', $fixture['payment_id'])->update([
                    'status' => 'paid',
                    'paid_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            touch($launchFile);

            $results = [];
            foreach ($processes as $workerId => $process) {
                $exitCode = $process->wait();
                self::assertSame(0, $exitCode, 'Reconciliation worker ' . $workerId . ' failed: ' . trim($process->getErrorOutput()));
                $results[] = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);
            }

            return [
                'results' => $results,
                'provider_calls' => file($providerLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [],
            ];
        } finally {
            touch($launchFile);
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(0.5);
                }
            }
            foreach (glob($directory . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($directory);
        }
    }

    private function allReady(string $directory, int $count): bool
    {
        for ($workerId = 0; $workerId < $count; $workerId++) {
            if (! is_file($directory . '/ready-' . $workerId)) {
                return false;
            }
        }

        return true;
    }

    /** @param list<Process> $processes */
    private function assertWorkersRunning(array $processes): void
    {
        foreach ($processes as $workerId => $process) {
            if (! $process->isRunning()) {
                $this->fail('Reconciliation worker ' . $workerId . ' exited early: ' . trim($process->getErrorOutput()));
            }
            $process->checkTimeout();
        }
    }

    private function reconciliationWorkerCode(): string
    {
        return <<<'PHP'
            require getcwd().'/vendor/autoload.php';
            try {
                $input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
                $app = require getcwd().'/bootstrap/app.php';
                $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
                config([
                    'database.default' => 'pgsql',
                    'database.connections.pgsql' => array_replace(config('database.connections.pgsql'), $input['database_config']),
                ]);
                \Illuminate\Support\Facades\DB::purge('pgsql');
                \Illuminate\Support\Facades\DB::setDefaultConnection('pgsql');
                $identity = \Illuminate\Support\Facades\DB::connection('pgsql')->selectOne(
                    'SELECT current_database() AS database_name, current_user AS role_name, host(inet_server_addr()) AS server_address, inet_server_port() AS server_port'
                );
                if ($identity->database_name !== $input['expected_database']
                    || $identity->role_name !== $input['expected_role']
                    || $identity->server_address !== $input['expected_host']
                    || (int) $identity->server_port !== (int) $input['expected_port']) {
                    throw new \RuntimeException('Reconciliation worker database identity mismatch.');
                }
                touch($input['ready_file']);
                $deadline = microtime(true) + 30;
                while (! is_file($input['launch_file']) && microtime(true) < $deadline) { usleep(1000); }
                if (! is_file($input['launch_file'])) { throw new \RuntimeException('Reconciliation worker launch timed out.'); }

                $gateway = \Mockery::mock(\App\Domains\Payment\Contracts\PaymentGateway::class);
                $gateway->shouldReceive('verify')->zeroOrMoreTimes()->andReturnUsing(function (string $reference) use ($input): array {
                    file_put_contents($input['provider_log'], $reference.PHP_EOL, FILE_APPEND | LOCK_EX);
                    return ['success' => true, 'status' => 'pending', 'reference' => $reference, 'amount' => 300000, 'currency' => 'NGN'];
                });
                $app->instance(\App\Domains\Payment\Contracts\PaymentGateway::class, $gateway);
                $exit = \Illuminate\Support\Facades\Artisan::call('payments:reconcile-subscription-checkouts', ['--limit' => 50]);
                echo json_encode(['exit' => $exit, 'output' => \Illuminate\Support\Facades\Artisan::output()], JSON_THROW_ON_ERROR).PHP_EOL;
            } catch (\Throwable $exception) {
                fwrite(STDERR, get_class($exception).': '.$exception->getMessage().PHP_EOL);
                exit(1);
            }
            PHP;
    }
}

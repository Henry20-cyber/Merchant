<?php

namespace Tests\Feature\Payment;

use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Models\BusinessUser;
use App\Domains\Payment\Contracts\PaymentGateway;
use App\Domains\Payment\Models\Payment;
use App\Domains\Payment\Services\PaymentConfirmationService;
use App\Domains\Subscription\Models\Subscription;
use App\Domains\Subscription\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PaymentCheckoutConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_concurrent_checkout_requests_create_only_one_paystack_payment(): void
    {
        $fixture = $this->createFixture();

        try {
            $workers = $this->runWorkers($fixture, 'checkout', [
                ['initialize-0', 'initialize-1'],
                ['verify-0', 'verify-1'],
            ]);

            $connection = DB::connection($fixture['connection_name']);
            $payments = $connection->table('payments')
                ->where('business_id', $fixture['business_id'])
                ->where('method', 'paystack')
                ->whereNull('sale_id')
                ->get();
            $providerCalls = $workers['provider_calls'];
            $references = array_unique(array_column($workers['results'], 'reference'));

            $this->assertCount(2, $workers['results']);
            $this->assertCount(1, $payments);
            $this->assertSame('pending', $payments->first()->status);
            $this->assertSame($fixture['subscription_id'], $payments->first()->subscription_id);
            $this->assertSame(1, count(array_filter($providerCalls, fn (string $call): bool => str_starts_with($call, 'initialize:'))));
            $this->assertSame(1, count(array_filter($providerCalls, fn (string $call): bool => str_starts_with($call, 'verify:'))));
            $this->assertCount(1, $references);
        } finally {
            $this->deleteFixture($fixture);
        }
    }

    public function test_concurrent_manual_confirmations_capture_and_activate_once(): void
    {
        $fixture = $this->createFixture(withPendingPayment: true);

        try {
            $workers = $this->runWorkers($fixture, 'confirm', [
                ['verify-0'],
                ['verify-1'],
            ]);

            $connection = DB::connection($fixture['connection_name']);
            $payment = $connection->table('payments')->where('id', $fixture['payment_id'])->first();
            $subscription = $connection->table('subscriptions')->where('id', $fixture['subscription_id'])->first();
            $providerCalls = $workers['provider_calls'];

            $this->assertCount(2, $workers['results']);
            $this->assertSame(['paid', 'paid'], array_column($workers['results'], 'status'));
            $this->assertSame('paid', $payment->status);
            $this->assertNotNull($payment->paid_at);
            $this->assertSame('active', $subscription->status);
            $this->assertSame($fixture['plan_id'], $subscription->plan_id);
            $this->assertNotNull($subscription->current_period_end);
            $this->assertSame(2, count(array_filter($providerCalls, fn (string $call): bool => str_starts_with($call, 'verify:'))));
            $this->assertCount(1, $connection->table('payments')->where('reference', $fixture['payment_reference'])->get());
        } finally {
            $this->deleteFixture($fixture);
        }
    }

    /** @return array<string, mixed> */
    private function createFixture(bool $withPendingPayment = false): array
    {
        $originalConnection = DB::getDefaultConnection();
        $connectionName = 'payment_race_fixture_' . bin2hex(random_bytes(4));
        $connectionConfig = config('database.connections.pgsql');
        config(["database.connections.{$connectionName}" => $connectionConfig]);
        DB::purge($connectionName);
        DB::setDefaultConnection($connectionName);
        $originalTeamId = getPermissionsTeamId();

        try {
            $business = Business::factory()->create();
            $businessTypeId = $business->business_type_id;
            $actor = User::factory()->create();
            BusinessUser::query()->create([
                'business_id' => $business->id,
                'user_id' => $actor->id,
                'status' => 'active',
                'joined_at' => now(),
            ]);

            $permission = Permission::query()->firstOrCreate([
                'name' => 'subscription.manage',
                'guard_name' => 'web',
            ]);
            setPermissionsTeamId($business->id);
            $role = Role::query()->create([
                'name' => 'Concurrency Owner ' . Str::lower(Str::random(8)),
                'guard_name' => 'web',
                'team_id' => $business->id,
                'is_system' => true,
            ]);
            $role->givePermissionTo($permission);
            $actor->assignRole($role);

            $slugSuffix = Str::lower(Str::random(12));
            $plan = SubscriptionPlan::factory()->create([
                'name' => 'Concurrency Monthly',
                'slug' => 'concurrency-monthly-' . $slugSuffix,
                'price' => '10000.00',
                'currency' => 'NGN',
                'billing_interval' => 'monthly',
                'paystack_plan_code' => 'PLN_concurrency_' . $slugSuffix,
                'is_active' => true,
            ]);
            $subscription = Subscription::factory()->create([
                'business_id' => $business->id,
                'plan_id' => $plan->id,
                'status' => 'trial',
                'provider' => null,
                'provider_customer_code' => null,
                'provider_authorization_code' => null,
                'provider_subscription_code' => null,
                'provisioning_status' => 'not_required',
            ]);

            $payment = null;
            if ($withPendingPayment) {
                $paymentReference = 'MERCHANTOS-CONFIRM-RACE-' . strtoupper(Str::random(12));
                $payment = Payment::factory()->create([
                    'business_id' => $business->id,
                    'subscription_id' => $subscription->id,
                    'sale_id' => null,
                    'amount' => $plan->price,
                    'method' => 'paystack',
                    'status' => 'pending',
                    'reference' => $paymentReference,
                    'metadata' => [
                        'type' => 'subscription',
                        'business_id' => $business->id,
                        'subscription_id' => $subscription->id,
                        'plan_id' => $plan->id,
                        'plan_slug' => $plan->slug,
                        'billing_interval' => $plan->billing_interval,
                        'currency' => 'NGN',
                        'paystack_plan_code' => $plan->paystack_plan_code,
                    ],
                ]);
            }

            return [
                'connection_name' => $connectionName,
                'connection_config' => $connectionConfig,
                'business_id' => $business->id,
                'business_type_id' => $businessTypeId,
                'actor_id' => $actor->id,
                'role_id' => $role->id,
                'plan_id' => $plan->id,
                'plan_slug' => $plan->slug,
                'plan_code' => $plan->paystack_plan_code,
                'subscription_id' => $subscription->id,
                'payment_id' => $payment?->id,
                'payment_reference' => $payment?->reference,
                'email' => $actor->email,
                'amount_minor' => 1_000_000,
            ];
        } finally {
            setPermissionsTeamId($originalTeamId);
            DB::setDefaultConnection($originalConnection);
        }
    }

    /** @param array<string, mixed> $fixture */
    private function deleteFixture(array $fixture): void
    {
        $connection = DB::connection($fixture['connection_name']);
        $connection->table('payments')->where('business_id', $fixture['business_id'])->delete();
        $connection->table('subscription_checkout_recoveries')->where('business_id', $fixture['business_id'])->delete();
        $connection->table('subscriptions')->where('business_id', $fixture['business_id'])->delete();
        $connection->table('business_user')->where('business_id', $fixture['business_id'])->delete();
        $connection->table('model_has_permissions')
            ->where('model_id', $fixture['actor_id'])
            ->where('model_type', User::class)
            ->delete();
        $connection->table('model_has_roles')
            ->where('model_id', $fixture['actor_id'])
            ->where('model_type', User::class)
            ->delete();
        $connection->table('role_has_permissions')->where('role_id', $fixture['role_id'])->delete();
        $connection->table('roles')->where('id', $fixture['role_id'])->delete();
        $connection->table('users')->where('id', $fixture['actor_id'])->delete();
        $connection->table('businesses')->where('id', $fixture['business_id'])->delete();
        $connection->table('subscription_plans')->where('id', $fixture['plan_id'])->delete();
        $connection->table('business_types')->where('id', $fixture['business_type_id'])->delete();
        DB::purge($fixture['connection_name']);
    }

    /** @param array<string, mixed> $fixture
     *  @param list<list<string>> $gateGroups
     *  @return array{results: list<array<string, mixed>>, provider_calls: list<string>}
     */
    private function runWorkers(array $fixture, string $operation, array $gateGroups): array
    {
        $directory = sys_get_temp_dir() . '/account-payment-race-' . bin2hex(random_bytes(6));
        if (! mkdir($directory, 0700)) {
            throw new \RuntimeException('Unable to create concurrency worker directory.');
        }

        $launchFile = $directory . '/launch';
        $providerReleaseFile = $directory . '/provider-release';
        $providerLog = $directory . '/provider-calls.log';
        file_put_contents($providerLog, '');

        $databaseConfig = array_intersect_key($fixture['connection_config'], array_flip([
            'driver', 'host', 'port', 'database', 'username', 'password',
            'charset', 'prefix', 'prefix_indexes', 'search_path', 'sslmode',
        ]));
        $databaseConfig['url'] = null;

        $processes = [];
        $workerInputs = [];
        $workerCode = $this->workerCode();

        try {
            for ($workerId = 0; $workerId < 2; $workerId++) {
                $input = array_merge($fixture, [
                    'operation' => $operation,
                    'worker_id' => $workerId,
                    'database_config' => $databaseConfig,
                    'expected_database' => 'account_concurrency_test',
                    'expected_host' => '127.0.0.1',
                    'expected_port' => 5432,
                    'expected_role' => 'postgres',
                    'ready_file' => $directory . '/ready-' . $workerId,
                    'launch_file' => $launchFile,
                    'provider_release_file' => $providerReleaseFile,
                    'provider_log' => $providerLog,
                    'gate_directory' => $directory,
                ]);
                unset($input['connection_config']);
                $workerInputs[] = $input;
                $process = new Process(
                    [PHP_BINARY, '-r', $workerCode],
                    base_path(),
                    ['APP_ENV' => 'testing'],
                    json_encode($input, JSON_THROW_ON_ERROR),
                    60,
                );
                $process->start();
                $processes[] = $process;
            }

            $readyDeadline = microtime(true) + 30;
            while (! $this->allFilesExist(array_column($workerInputs, 'ready_file')) && microtime(true) < $readyDeadline) {
                $this->assertWorkersStillRunning($processes);
                usleep(1000);
            }
            $this->assertTrue($this->allFilesExist(array_column($workerInputs, 'ready_file')), 'Concurrency workers did not establish their approved database connections.');
            touch($launchFile);

            $gateDeadline = microtime(true) + 30;
            while (! $this->anyFileExistsForEachGroup($directory, $gateGroups) && microtime(true) < $gateDeadline) {
                $this->assertWorkersStillRunning($processes);
                usleep(1000);
            }
            $this->assertTrue($this->anyFileExistsForEachGroup($directory, $gateGroups), 'Workers did not reach the expected provider verification barrier.');
            touch($providerReleaseFile);

            $results = [];
            foreach ($processes as $workerId => $process) {
                $exitCode = $process->wait();
                $this->assertSame(0, $exitCode, 'Concurrency worker ' . $workerId . ' failed: ' . trim($process->getErrorOutput()));
                $decoded = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);
                $this->assertIsArray($decoded);
                $results[] = $decoded;
            }

            $providerCalls = file($providerLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

            return ['results' => $results, 'provider_calls' => $providerCalls];
        } finally {
            touch($launchFile);
            touch($providerReleaseFile);
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

    /** @param list<Process> $processes */
    private function assertWorkersStillRunning(array $processes): void
    {
        foreach ($processes as $workerId => $process) {
            if (! $process->isRunning()) {
                $this->fail('Concurrency worker ' . $workerId . ' exited before the barrier (exit=' . var_export($process->getExitCode(), true) . ').');
            }
            $process->checkTimeout();
        }
    }

    /** @param list<list<string>> $groups */
    private function anyFileExistsForEachGroup(string $directory, array $groups): bool
    {
        foreach ($groups as $group) {
            $found = false;
            foreach ($group as $file) {
                if (is_file($directory . '/' . $file)) {
                    $found = true;
                    break;
                }
            }
            if (! $found) {
                return false;
            }
        }

        return true;
    }

    /** @param list<string> $files */
    private function allFilesExist(array $files): bool
    {
        foreach ($files as $file) {
            if (! is_file($file)) {
                return false;
            }
        }

        return true;
    }

    private function workerCode(): string
    {
        return <<<'PHP'
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
                ]);
                \Illuminate\Support\Facades\DB::purge('pgsql');
                \Illuminate\Support\Facades\DB::setDefaultConnection('pgsql');

                $connection = \Illuminate\Support\Facades\DB::connection('pgsql');
                $identity = $connection->selectOne('SELECT current_database() AS database_name, current_user AS role_name, host(inet_server_addr()) AS server_address, inet_server_port() AS server_port');
                if ($identity->database_name !== $input['expected_database']
                    || $identity->role_name !== $input['expected_role']
                    || $identity->server_address !== $input['expected_host']
                    || (int) $identity->server_port !== (int) $input['expected_port']) {
                    throw new \RuntimeException('Worker database identity mismatch.');
                }

                if (file_put_contents($input['ready_file'], 'ready') === false) {
                    throw new \RuntimeException('Worker could not signal readiness.');
                }
                $waitUntilReleased = static function (string $gate) use ($input): void {
                    $gateFile = $input['gate_directory'].'/'.$gate.'-'.$input['worker_id'];
                    file_put_contents($input['provider_log'], $gate.':'.$input['worker_id'].PHP_EOL, FILE_APPEND | LOCK_EX);
                    touch($gateFile);
                    $deadline = microtime(true) + 30;
                    while (! is_file($input['provider_release_file']) && microtime(true) < $deadline) {
                        usleep(1000);
                    }
                    if (! is_file($input['provider_release_file'])) {
                        throw new \RuntimeException('Worker timed out at provider barrier.');
                    }
                };

                $deadline = microtime(true) + 30;
                while (! is_file($input['launch_file']) && microtime(true) < $deadline) {
                    usleep(1000);
                }
                if (! is_file($input['launch_file'])) {
                    throw new \RuntimeException('Worker timed out waiting for synchronized launch.');
                }

                $gateway = \Mockery::mock(\App\Domains\Payment\Contracts\PaymentGateway::class);
                $gateway->shouldReceive('initialize')->zeroOrMoreTimes()->andReturnUsing(function (array $data) use ($input, $waitUntilReleased): array {
                    $waitUntilReleased('initialize');
                    return [
                        'success' => true,
                        'authorization_url' => 'https://checkout.paystack.com/concurrency-test',
                        'access_code' => 'test-access-code',
                        'reference' => $data['reference'],
                        'raw' => [],
                    ];
                });
                $gateway->shouldReceive('verify')->zeroOrMoreTimes()->andReturnUsing(function (string $reference) use ($input, $waitUntilReleased): array {
                    $waitUntilReleased('verify');
                    return [
                        'success' => true,
                        'status' => $input['operation'] === 'checkout' ? 'pending' : 'success',
                        'reference' => $reference,
                        'amount' => $input['amount_minor'],
                        'currency' => 'NGN',
                        'authorization_code' => null,
                        'customer_code' => null,
                        'raw' => [],
                    ];
                });
                $app->instance(\App\Domains\Payment\Contracts\PaymentGateway::class, $gateway);

                if ($input['operation'] === 'checkout') {
                    $actor = \App\Models\User::query()->findOrFail($input['actor_id']);
                    $business = \App\Domains\Organization\Models\Business::query()->findOrFail($input['business_id']);
                    $plan = \App\Domains\Subscription\Models\SubscriptionPlan::query()->findOrFail($input['plan_id']);
                    $result = app(\App\Domains\Subscription\Services\SubscriptionCheckoutService::class)
                        ->checkout($actor, $business, $plan, $input['email']);
                    echo json_encode([
                        'reference' => $result['reference'] ?? null,
                        'payment_id' => $result['payment_id'] ?? null,
                        'status' => $result['status'] ?? 'initialized',
                    ], JSON_THROW_ON_ERROR).PHP_EOL;
                } else {
                    $payment = app(\App\Domains\Payment\Services\PaymentConfirmationService::class)
                        ->confirm($input['payment_reference']);
                    echo json_encode([
                        'reference' => $payment->reference,
                        'payment_id' => $payment->id,
                        'status' => $payment->status,
                    ], JSON_THROW_ON_ERROR).PHP_EOL;
                }
            } catch (\Throwable $exception) {
                fwrite(STDERR, get_class($exception).PHP_EOL);
                exit(1);
            }
            PHP;
    }
}

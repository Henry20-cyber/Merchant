<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use PDO;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        // Boot Laravel to resolve config, but do not enter PHPUnit trait setup
        // (including RefreshDatabase) until the target is verified.
        $this->refreshApplication();
        $this->assertSafeTestDatabase();

        parent::setUp();

        $this->withHeaders([
            'Origin' => 'http://localhost:5173',
            'Referer' => 'http://localhost:5173/',
        ]);
    }

    private function assertSafeTestDatabase(): void
    {
        $expected = [
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'port' => 5432,
            'database' => 'account_concurrency_test',
            'username' => 'postgres',
        ];
        $default = config('database.default');
        $resolved = config('database.connections.pgsql');
        $root = dirname(__DIR__);
        $appEnvironmentPath = $root . '/.env';
        if (! is_file($appEnvironmentPath)) {
            throw new RuntimeException('Refusing test setup: application database target cannot be verified.');
        }

        $appEnvironment = \Dotenv\Dotenv::parse(file_get_contents($appEnvironmentPath));
        $applicationDatabase = $appEnvironment['DB_DATABASE'] ?? null;
        $applicationUrl = $appEnvironment['DB_URL'] ?? '';
        $applicationUrlDatabase = is_string($applicationUrl) && $applicationUrl !== ''
            ? ltrim((string) (parse_url($applicationUrl, PHP_URL_PATH) ?: ''), '/')
            : null;
        $applicationDatabase = $applicationUrlDatabase ?: $applicationDatabase;

        if ($default !== $expected['driver']
            || ($resolved['driver'] ?? null) !== $expected['driver']
            || ($resolved['host'] ?? null) !== $expected['host']
            || (int) ($resolved['port'] ?? 0) !== $expected['port']
            || ($resolved['database'] ?? null) !== $expected['database']
            || ($resolved['username'] ?? null) !== $expected['username']
            || filled($resolved['url'] ?? null)
            || ! is_string($applicationDatabase)
            || $applicationDatabase === ''
            || $applicationDatabase === $expected['database']
            || in_array($expected['database'], ['postgres', 'merchantos_testing'], true)) {
            throw new RuntimeException('Refusing test setup: database target is not the approved isolated test database.');
        }

        try {
            $connection = $this->app['db']->connection($expected['driver']);
            $pdo = $connection->getPdo();
        } catch (\Throwable $exception) {
            throw new RuntimeException(
                'Refusing test setup: Laravel could not connect to the approved isolated test database.'
                . $this->safeDatabaseErrorCode($exception)
            );
        }

        try {
            $identity = $pdo->query(<<<'SQL'
                SELECT current_database() AS database_name,
                       current_user AS role_name,
                       host(inet_server_addr()) AS server_address,
                       inet_server_port() AS server_port,
                       role_info.rolsuper AS is_superuser,
                       db_info.datdba = role_info.oid AS owns_database,
                       has_database_privilege(current_user, current_database(), 'CONNECT') AS can_connect,
                       has_database_privilege(current_user, current_database(), 'CREATE') AS can_create_in_database,
                       has_schema_privilege(current_user, 'public', 'USAGE') AS can_use_public,
                       has_schema_privilege(current_user, 'public', 'CREATE') AS can_create_in_public,
                       (
                           SELECT count(*)
                           FROM pg_class relation_info
                           JOIN pg_namespace namespace_info ON namespace_info.oid = relation_info.relnamespace
                           WHERE namespace_info.nspname = 'public'
                             AND relation_info.relkind IN ('r', 'p', 'v', 'm', 'S', 'f')
                             AND relation_info.relowner <> role_info.oid
                       ) AS foreign_relation_count,
                       (
                           SELECT count(*)
                           FROM pg_type type_info
                           JOIN pg_namespace namespace_info ON namespace_info.oid = type_info.typnamespace
                           WHERE namespace_info.nspname = 'public'
                             AND type_info.typtype <> 'p'
                             AND type_info.typowner <> role_info.oid
                       ) AS foreign_type_count
                FROM pg_roles role_info
                JOIN pg_database db_info ON db_info.datname = current_database()
                WHERE role_info.rolname = current_user
                SQL)->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable $exception) {
            throw new RuntimeException(
                'Refusing test setup: read-only database identity and privilege verification failed.'
                . $this->safeDatabaseErrorCode($exception)
            );
        }

        $ownsTestObjects = (int) ($identity['foreign_relation_count'] ?? -1) === 0
            && (int) ($identity['foreign_type_count'] ?? -1) === 0;
        $sufficientPrivileges = filter_var($identity['is_superuser'] ?? false, FILTER_VALIDATE_BOOLEAN)
            || (
                filter_var($identity['owns_database'] ?? false, FILTER_VALIDATE_BOOLEAN)
                && filter_var($identity['can_create_in_database'] ?? false, FILTER_VALIDATE_BOOLEAN)
                && filter_var($identity['can_use_public'] ?? false, FILTER_VALIDATE_BOOLEAN)
                && filter_var($identity['can_create_in_public'] ?? false, FILTER_VALIDATE_BOOLEAN)
                && $ownsTestObjects
            );

        $checks = [
            'database_matches' => ($identity['database_name'] ?? null) === $expected['database'],
            'role_matches' => ($identity['role_name'] ?? null) === $expected['username'],
            'server_address_matches' => ($identity['server_address'] ?? null) === $expected['host'],
            'server_port_matches' => (int) ($identity['server_port'] ?? 0) === $expected['port'],
            'can_connect' => filter_var($identity['can_connect'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'sufficient_privileges' => $sufficientPrivileges,
        ];

        if (in_array(false, $checks, true)) {
            $safeDiagnostics = [
                'connected_database' => $identity['database_name'] ?? null,
                'connected_role' => $identity['role_name'] ?? null,
                'server_address' => $identity['server_address'] ?? null,
                'server_port' => isset($identity['server_port']) ? (int) $identity['server_port'] : null,
                'checks' => $checks,
                'privilege_checks' => [
                    'is_superuser' => filter_var($identity['is_superuser'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'owns_database' => filter_var($identity['owns_database'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'can_create_in_database' => filter_var($identity['can_create_in_database'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'can_use_public' => filter_var($identity['can_use_public'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'can_create_in_public' => filter_var($identity['can_create_in_public'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'foreign_relation_count_is_zero' => (int) ($identity['foreign_relation_count'] ?? -1) === 0,
                    'foreign_type_count_is_zero' => (int) ($identity['foreign_type_count'] ?? -1) === 0,
                    'owns_test_objects' => $ownsTestObjects,
                ],
                'pdo_boolean_values' => [
                    'can_connect' => $identity['can_connect'] ?? null,
                    'is_superuser' => $identity['is_superuser'] ?? null,
                    'owns_database' => $identity['owns_database'] ?? null,
                    'can_create_in_database' => $identity['can_create_in_database'] ?? null,
                    'can_use_public' => $identity['can_use_public'] ?? null,
                    'can_create_in_public' => $identity['can_create_in_public'] ?? null,
                ],
                'foreign_relation_count' => isset($identity['foreign_relation_count'])
                    ? (int) $identity['foreign_relation_count']
                    : null,
                'foreign_type_count' => isset($identity['foreign_type_count'])
                    ? (int) $identity['foreign_type_count']
                    : null,
            ];

            throw new RuntimeException(
                'Refusing test setup: connected database identity or destructive test privileges do not match the approved isolated target. Safe diagnostics: '
                . json_encode($safeDiagnostics, JSON_UNESCAPED_SLASHES)
            );
        }
    }

    private function safeDatabaseErrorCode(\Throwable $exception): string
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            $publicProperties = get_object_vars($current);
            $errorInfo = is_array($publicProperties['errorInfo'] ?? null)
                ? $publicProperties['errorInfo']
                : [];
            $sqlState = $errorInfo[0] ?? $current->getCode();
            $driverCode = $errorInfo[1] ?? null;

            if (preg_match('/^[A-Z0-9]{5}$/', (string) $sqlState)) {
                $detail = ' SQLSTATE ' . $sqlState;

                if (is_int($driverCode) || (is_string($driverCode) && ctype_digit($driverCode))) {
                    $detail .= ', PostgreSQL driver code ' . $driverCode;
                }

                return $detail . '.';
            }
        }

        return ' No SQLSTATE was available.';
    }
}

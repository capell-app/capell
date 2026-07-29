<?php

declare(strict_types=1);

namespace Capell\Tests\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

final class PackageTestDatabaseGuard
{
    private const array BLOCKED_DATABASES = [
        'capell_ruby',
        'capell_ruby_test',
    ];

    public static function assertEnvironmentIsSafe(): void
    {
        self::assertSafe(
            connection: getenv('DB_CONNECTION') !== false ? (string) getenv('DB_CONNECTION') : null,
            database: getenv('DB_DATABASE') !== false ? (string) getenv('DB_DATABASE') : null,
            url: getenv('DB_URL') !== false ? (string) getenv('DB_URL') : null,
            source: 'environment',
        );
    }

    public static function assertConfigurationIsSafe(Application $app): void
    {
        $config = $app->make(Repository::class);
        $connection = $config->get('database.default');
        $connectionName = is_string($connection) && $connection !== '' ? $connection : 'sqlite';
        $database = $config->get(sprintf('database.connections.%s.database', $connectionName));
        $url = $config->get(sprintf('database.connections.%s.url', $connectionName));

        self::assertSafe(
            connection: is_string($connection) ? $connection : null,
            database: is_string($database) ? $database : null,
            url: is_string($url) ? $url : null,
            source: 'configuration',
        );

        $resolvedConnection = $app->make(DatabaseManager::class)->connection();
        $requestedConnection = getenv('DB_CONNECTION');

        self::assertRequestedDriverResolved(
            is_string($requestedConnection) ? $requestedConnection : null,
            $resolvedConnection->getDriverName(),
        );
        self::assertSafe(
            connection: $resolvedConnection->getDriverName(),
            database: $resolvedConnection->getDatabaseName(),
            url: null,
            source: 'resolved configuration',
        );
    }

    public static function assertRequestedDriverResolved(?string $requested, mixed $resolved): void
    {
        $requested = strtolower(trim((string) $requested));
        $requested = $requested === '' ? 'sqlite' : $requested;
        $requested = in_array($requested, ['postgres', 'postgresql'], true) ? 'pgsql' : $requested;
        $requested = $requested === 'mariadb' ? 'mysql' : $requested;

        if (! is_string($resolved) || strtolower($resolved) !== $requested) {
            throw new RuntimeException(sprintf(
                'Requested package test database driver [%s] resolved to [%s].',
                $requested,
                is_scalar($resolved) ? (string) $resolved : get_debug_type($resolved),
            ));
        }
    }

    public static function assertSafe(?string $connection, ?string $database, ?string $url, string $source): void
    {
        $databaseNames = array_values(array_filter([
            self::normaliseDatabaseName($database),
            self::databaseNameFromUrl($url),
        ], is_string(...)));

        if (self::isServerConnection($connection) && $databaseNames === []) {
            throw new RuntimeException(sprintf(
                'Refusing to run Capell package Pest tests without an explicit dedicated test database from %s connection [%s].',
                $source,
                $connection,
            ));
        }

        foreach ($databaseNames as $databaseName) {
            if (in_array($databaseName, self::BLOCKED_DATABASES, true)) {
                throw new RuntimeException(sprintf(
                    'Refusing to run Capell package Pest tests against [%s] from %s %s. Use sqlite :memory: or a dedicated package test database.',
                    $databaseName,
                    $source,
                    $connection !== null ? sprintf('connection [%s]', $connection) : 'database settings',
                ));
            }

            if (self::isServerConnection($connection) && preg_match('/(?:^|[_-])test(?:$|[_-])/i', $databaseName) !== 1) {
                throw new RuntimeException(sprintf(
                    'Refusing to run Capell package Pest tests against database [%s] without a dedicated test name from %s connection [%s].',
                    $databaseName,
                    $source,
                    $connection,
                ));
            }
        }
    }

    private static function isServerConnection(?string $connection): bool
    {
        return in_array(strtolower($connection ?? ''), ['mariadb', 'mysql', 'pgsql', 'postgres', 'postgresql'], true);
    }

    private static function databaseNameFromUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH);

        return is_string($path) ? self::normaliseDatabaseName($path) : null;
    }

    private static function normaliseDatabaseName(?string $database): ?string
    {
        if ($database === null || $database === '') {
            return null;
        }

        if ($database === ':memory:') {
            return null;
        }

        return basename(trim($database, '/'));
    }
}

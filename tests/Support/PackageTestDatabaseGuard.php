<?php

declare(strict_types=1);

namespace Capell\Tests\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
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
        $connection = $app->make(Repository::class)->get('database.default');
        $database = $app->make(Repository::class)->get(sprintf('database.connections.%s.database', $connection));
        $url = $app->make(Repository::class)->get(sprintf('database.connections.%s.url', $connection));

        self::assertSafe(
            connection: is_string($connection) ? $connection : null,
            database: is_string($database) ? $database : null,
            url: is_string($url) ? $url : null,
            source: 'configuration',
        );
    }

    public static function assertSafe(?string $connection, ?string $database, ?string $url, string $source): void
    {
        $databaseNames = array_filter([
            self::normaliseDatabaseName($database),
            self::databaseNameFromUrl($url),
        ]);

        foreach ($databaseNames as $databaseName) {
            if (in_array($databaseName, self::BLOCKED_DATABASES, true)) {
                throw new RuntimeException(sprintf(
                    'Refusing to run Capell package Pest tests against [%s] from %s %s. Use sqlite :memory: or a dedicated package test database.',
                    $databaseName,
                    $source,
                    $connection !== null ? sprintf('connection [%s]', $connection) : 'database settings',
                ));
            }
        }
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

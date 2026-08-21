<?php

declare(strict_types=1);

use Capell\Core\Enums\Database\DatabaseFamily;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Facades\CapellDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('runs the complete schema on the requested database family and proven version', function (): void {
    $requestedFamily = getenv('CAPELL_TEST_DATABASE_FAMILY');
    $requestedFamily = is_string($requestedFamily) && $requestedFamily !== '' ? $requestedFamily : 'sqlite';
    $minimumVersion = getenv('CAPELL_TEST_DATABASE_VERSION');
    $minimumVersion = is_string($minimumVersion) && $minimumVersion !== '' ? $minimumVersion : 'runtime';
    $connection = DB::connection();
    $serverVersion = match ($requestedFamily) {
        'mysql', 'mariadb' => (string) $connection->selectOne('SELECT VERSION() AS version')->version,
        'postgresql' => (string) $connection->selectOne('SHOW server_version')->server_version,
        'sqlite' => (string) $connection->selectOne('SELECT sqlite_version() AS version')->version,
        default => throw new LogicException(sprintf('Unknown database portability family [%s].', $requestedFamily)),
    };
    $expectedDriver = match ($requestedFamily) {
        'mariadb', 'mysql' => 'mysql',
        'postgresql' => 'pgsql',
        'sqlite' => 'sqlite',
    };
    $expectedPlatformFamily = match ($requestedFamily) {
        'mariadb' => DatabaseFamily::MariaDb,
        'mysql' => DatabaseFamily::MySql,
        'postgresql' => DatabaseFamily::PostgreSql,
        'sqlite' => DatabaseFamily::Sqlite,
    };
    preg_match('/\d+\.\d+(?:\.\d+)?/', $serverVersion, $versionMatches);
    $normalisedVersion = $versionMatches[0] ?? '';
    $ranMigrations = DB::table('migrations')->pluck('migration')->all();

    expect($connection->getDriverName())->toBe($expectedDriver)
        ->and(CapellDatabase::for($connection)->family())->toBe($expectedPlatformFamily)
        ->and($serverVersion)->not->toBe('')
        ->and($normalisedVersion)->not->toBe('')
        ->and(array_values(array_diff(CapellCore::getMigrations(), $ranMigrations)))->toBe([])
        ->and(Schema::hasTable('sites'))->toBeTrue()
        ->and(Schema::hasTable('pages'))->toBeTrue()
        ->and(Schema::hasTable('capell_upgrade_log'))->toBeTrue()
        ->and(Schema::hasTable('capell_upgrade_runs'))->toBeTrue();

    if ($minimumVersion !== 'runtime') {
        expect(version_compare($normalisedVersion, $minimumVersion, '>='))->toBeTrue();
    }

    if ($requestedFamily === 'mariadb') {
        expect(strtolower($serverVersion))->toContain('mariadb');
    }

    if ($requestedFamily === 'mysql') {
        expect(strtolower($serverVersion))->not->toContain('mariadb');
    }
});

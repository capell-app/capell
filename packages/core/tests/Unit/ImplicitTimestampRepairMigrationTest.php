<?php

declare(strict_types=1);

use Capell\Core\Contracts\Database\DatabasePlatform;
use Capell\Core\Contracts\Database\DatabaseSchemaDialect;
use Capell\Core\Facades\CapellDatabase;
use Capell\Core\Support\Database\DatabasePlatformRegistry;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Migrations\Migration;

it('keeps the published timestamp repair independent of movable code and config', function (): void {
    $source = (string) file_get_contents(dirname(__DIR__, 2) . '/database/migrations/2026_09_29_000001_remove_implicit_timestamp_updates.php');

    // Match the consuming application's post N-1 migration hygiene rules.
    expect(preg_match('/(?:^use\s+|\\\\|\b)(?:App|Capell)\\\\/m', $source))->toBe(0)
        ->and(preg_match('/\b(?:app|config|resolve)\s*\(/', $source))->toBe(0);
});

it('repairs the complete implicit first timestamp shape in Core-created tables', function (): void {
    $implicitColumns = [];
    foreach (glob(dirname(__DIR__, 2) . '/database/migrations/*create*.php') ?: [] as $path) {
        $source = (string) file_get_contents($path);
        if (preg_match('/Schema::create\(\s*[\'"]([^\'"]+)[\'"]/', $source, $table) !== 1) {
            continue;
        }

        if (preg_match('/->timestamp(?:Tz)?\(\s*[\'"]([^\'"]+)[\'"]([^;]*);/', $source, $column) !== 1) {
            continue;
        }

        if (preg_match('/->(?:nullable|default|useCurrent)\(/', $column[2]) !== 1) {
            $implicitColumns[$table[1]] = $column[1];
        }
    }

    $migration = require dirname(__DIR__, 2) . '/database/migrations/2026_09_29_000001_remove_implicit_timestamp_updates.php';
    $targets = new ReflectionClass($migration)->getConstant('COLUMNS');
    expect($targets)->toBeArray()->not->toBeEmpty();
    throw_unless(is_array($targets), RuntimeException::class, 'Expected a Core timestamp repair map.');

    ksort($targets);
    ksort($implicitColumns);
    expect($targets)->toBe($implicitColumns);
});

it('is a no-op on SQLite and does not reverse the timestamp safety repair', function (): void {
    $migration = require dirname(__DIR__, 2) . '/database/migrations/2026_09_29_000001_remove_implicit_timestamp_updates.php';
    expect($migration)->toBeInstanceOf(Migration::class);
    $connection = resolve(ConnectionResolverInterface::class)->connection();
    $connection->enableQueryLog();
    $connection->flushQueryLog();

    $migration->up();
    $migration->down();

    expect($connection->getQueryLog())->toBe([]);
});

it('does not require timestamp repair from third-party schema dialects', function (): void {
    expect(new ReflectionClass(DatabaseSchemaDialect::class)->hasMethod('dropImplicitTimestampUpdate'))->toBeFalse();
});

it('does not consult package schema dialects when running the published repair', function (): void {
    $migration = require dirname(__DIR__, 2) . '/database/migrations/2026_09_29_000001_remove_implicit_timestamp_updates.php';
    $connection = resolve(ConnectionResolverInterface::class)->connection();
    $platform = Mockery::mock(DatabasePlatform::class);
    $platform->shouldReceive('drivers')->once()->andReturn(['sqlite']);
    $platform->shouldNotReceive('schemaDialect');
    CapellDatabase::swap(new DatabasePlatformRegistry([$platform]));
    $connection->enableQueryLog();
    $connection->flushQueryLog();

    $migration->up();

    expect($connection->getQueryLog())->toBe([]);
});

<?php

declare(strict_types=1);

use Capell\Core\Contracts\Database\DatabasePlatform;
use Capell\Core\Contracts\Database\DatabaseSchemaDialect;
use Capell\Core\Facades\CapellDatabase;
use Capell\Core\Support\Database\DatabasePlatformRegistry;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Migrations\Migration;

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

it('does not call a timestamp repair method on dialects without that capability', function (): void {
    $migration = require dirname(__DIR__, 2) . '/database/migrations/2026_09_29_000001_remove_implicit_timestamp_updates.php';
    $connection = resolve(ConnectionResolverInterface::class)->connection();
    $dialect = Mockery::mock(DatabaseSchemaDialect::class);
    $platform = Mockery::mock(DatabasePlatform::class);
    $platform->shouldReceive('drivers')->once()->andReturn(['sqlite']);
    $platform->shouldReceive('schemaDialect')->once()->andReturn($dialect);
    CapellDatabase::swap(new DatabasePlatformRegistry([$platform]));
    $connection->enableQueryLog();
    $connection->flushQueryLog();

    $migration->up();

    expect($connection->getQueryLog())->toBe([]);
});

<?php

declare(strict_types=1);

use Capell\Core\Contracts\Database\DatabasePlatform;
use Capell\Core\Enums\Database\DatabaseCapability;
use Capell\Core\Enums\Database\DatabaseFamily;
use Capell\Core\Exceptions\UnsupportedDatabaseDriver;
use Capell\Core\Facades\CapellDatabase;
use Capell\Core\Support\Database\DatabasePlatformRegistry;
use Capell\Core\Support\Database\Platforms\SqliteDatabasePlatform;
use Illuminate\Container\Container;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use Laravel\Octane\CurrentApplication;
use Laravel\Octane\Events\TaskReceived;
use Laravel\Octane\Events\TaskTerminated;
use Laravel\Octane\Listeners\CreateConfigurationSandbox;
use Laravel\Octane\Listeners\FlushTemporaryContainerInstances;
use Laravel\Octane\Listeners\GiveNewApplicationInstanceToDatabaseManager;

it('refuses to autowire a database platform registry without configured platforms', function (): void {
    expect(fn (): DatabasePlatformRegistry => new Container()->make(DatabasePlatformRegistry::class))
        ->toThrow(BindingResolutionException::class, '$platforms');
});

it('rejects an empty database platform array during construction', function (): void {
    expect(fn (): DatabasePlatformRegistry => new DatabasePlatformRegistry([]))
        ->toThrow(LogicException::class, 'DatabasePlatformRegistry requires at least one registered driver.');
});

it('rejects an empty database platform iterator during construction', function (): void {
    expect(fn (): DatabasePlatformRegistry => new DatabasePlatformRegistry(new ArrayIterator))
        ->toThrow(LogicException::class, 'DatabasePlatformRegistry requires at least one registered driver.');
});

it('rejects platform collections that register no drivers', function (): void {
    $platform = Mockery::mock(DatabasePlatform::class);
    $platform->shouldReceive('drivers')->once()->andReturn([]);

    expect(fn (): DatabasePlatformRegistry => new DatabasePlatformRegistry([$platform]))
        ->toThrow(LogicException::class, 'DatabasePlatformRegistry requires at least one registered driver.');
});

it('accepts a populated database platform iterator', function (): void {
    $platform = new SqliteDatabasePlatform;
    $registry = new DatabasePlatformRegistry(new ArrayIterator([$platform]));

    expect($registry->forDriver('sqlite'))->toBe($platform);
});

it('binds all built-in database platforms explicitly within an operation', function (): void {
    $registry = resolve(DatabasePlatformRegistry::class);

    expect(resolve(DatabasePlatformRegistry::class))->toBe($registry)
        ->and($registry->forDriver('mysql')->family())->toBe(DatabaseFamily::MySql)
        ->and($registry->forDriver('mariadb')->family())->toBe(DatabaseFamily::MariaDb)
        ->and($registry->forDriver('sqlite')->family())->toBe(DatabaseFamily::Sqlite)
        ->and($registry->forDriver('pgsql')->family())->toBe(DatabaseFamily::PostgreSql)
        ->and($registry->forDriver('postgresql')->family())->toBe(DatabaseFamily::PostgreSql);
});

it('discovers platforms tagged after boot resolution in subsequent Octane operations', function (): void {
    $application = app();
    $registry = resolve(DatabasePlatformRegistry::class);
    $platform = Mockery::mock(DatabasePlatform::class);
    $platform->shouldReceive('drivers')->twice()->andReturn(['custom']);
    $application->instance('custom-database-platform', $platform);
    $application->tag('custom-database-platform', DatabasePlatform::TAG);

    duringDatabasePlatformOperation($application, function (Application $sandbox) use ($registry): void {
        expect($sandbox->make(DatabasePlatformRegistry::class))->toBe($registry)
            ->and(fn (): DatabasePlatform => CapellDatabase::forDriver('custom'))
            ->toThrow(UnsupportedDatabaseDriver::class, 'Unsupported database driver [custom].');
    });

    foreach (range(1, 2) as $operation) {
        duringDatabasePlatformOperation($application, function (Application $sandbox) use ($registry, $platform): void {
            $resolved = $sandbox->make(DatabasePlatformRegistry::class);

            expect($resolved->forDriver('custom'))->toBe($platform)
                ->and(CapellDatabase::forDriver('custom'))->toBe($platform)
                ->and($resolved->forDriver('sqlite')->family())->toBe(DatabaseFamily::Sqlite)
                ->and($resolved)->not->toBe($registry);
        });
    }
});

it('refreshes database capabilities after reconnecting between Octane operations', function (
    string $replacementVersion,
    DatabaseFamily $replacementFamily,
): void {
    $application = app();
    $connections = resolve(DatabaseManager::class);
    $pdo = Mockery::mock(PDO::class);
    $pdo->shouldReceive('getAttribute')->once()->with(PDO::ATTR_SERVER_VERSION)->andReturn('8.0.36');
    config(['database.connections.platform_operation' => ['driver' => 'mysql']]);
    $connections->extend('platform_operation', function (array $configuration) use (&$pdo): Connection {
        return new Connection($pdo, 'platform_operation', '', $configuration);
    });
    $connection = $connections->connection('platform_operation');
    $registry = resolve(DatabasePlatformRegistry::class);

    expect(CapellDatabase::getFacadeRoot())->toBe($registry);

    duringDatabasePlatformOperation($application, function (Application $sandbox) use ($registry, $connection): void {
        expect($sandbox->make(DatabasePlatformRegistry::class))->toBe($registry)
            ->and(CapellDatabase::forConnection('platform_operation')->family())->toBe(DatabaseFamily::MySql)
            ->and($registry->forDriver('mysql')->schemaDialect()->supports(DatabaseCapability::JsonPathIndex, $connection))->toBeTrue();
    });

    $pdo = Mockery::mock(PDO::class);
    $pdo->shouldReceive('getAttribute')->once()->with(PDO::ATTR_SERVER_VERSION)->andReturn($replacementVersion);

    duringDatabasePlatformOperation($application, function (Application $sandbox) use ($connections, $connection, $pdo, $registry, $replacementFamily): void {
        $reconnected = $connections->reconnect('platform_operation');
        $resolved = $sandbox->make(DatabasePlatformRegistry::class);

        expect($reconnected)->toBe($connection)
            ->and($reconnected->getPdo())->toBe($pdo)
            ->and(CapellDatabase::forConnection('platform_operation')->family())->toBe($replacementFamily)
            ->and($resolved->forDriver('mysql')->schemaDialect()->supports(DatabaseCapability::JsonPathIndex, $reconnected))->toBeFalse()
            ->and($resolved)->not->toBe($registry);
    });
})->with([
    'mysql to mariadb' => ['10.11.8-MariaDB', DatabaseFamily::MariaDb],
    'mysql version downgrade' => ['5.7.44', DatabaseFamily::MySql],
]);

/**
 * @param  Closure(Application): void  $operation
 */
function duringDatabasePlatformOperation(Application $application, Closure $operation): void
{
    $sandbox = clone $application;
    CurrentApplication::set($sandbox);

    try {
        $received = new TaskReceived($application, $sandbox, null);
        new CreateConfigurationSandbox()->handle($received);
        new GiveNewApplicationInstanceToDatabaseManager()->handle($received);

        $operation($sandbox);
    } finally {
        new FlushTemporaryContainerInstances()->handle(new TaskTerminated($application, $sandbox, null, null));
        CurrentApplication::set($application);
        $application->make(DatabaseManager::class)->setApplication($application);
    }
}

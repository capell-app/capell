<?php

declare(strict_types=1);

use Capell\Core\Contracts\Database\DatabasePlatform;
use Capell\Core\Enums\Database\DatabaseFamily;
use Capell\Core\Support\Database\DatabasePlatformRegistry;
use Capell\Core\Support\Database\Platforms\SqliteDatabasePlatform;
use Illuminate\Container\Container;
use Illuminate\Contracts\Container\BindingResolutionException;

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

it('binds all built-in and tagged database platforms explicitly', function (): void {
    $platform = Mockery::mock(DatabasePlatform::class);
    $platform->shouldReceive('drivers')->once()->andReturn(['custom']);
    app()->instance('custom-database-platform', $platform);
    app()->tag('custom-database-platform', DatabasePlatform::TAG);
    app()->forgetInstance(DatabasePlatformRegistry::class);

    $registry = resolve(DatabasePlatformRegistry::class);

    expect($registry->forDriver('mysql')->family())->toBe(DatabaseFamily::MySql)
        ->and($registry->forDriver('mariadb')->family())->toBe(DatabaseFamily::MariaDb)
        ->and($registry->forDriver('sqlite')->family())->toBe(DatabaseFamily::Sqlite)
        ->and($registry->forDriver('pgsql')->family())->toBe(DatabaseFamily::PostgreSql)
        ->and($registry->forDriver('postgresql')->family())->toBe(DatabaseFamily::PostgreSql)
        ->and($registry->forDriver('custom'))->toBe($platform);
});

it('preserves registered database platforms across a scope reset', function (): void {
    $platform = Mockery::mock(DatabasePlatform::class);
    $platform->shouldReceive('drivers')->once()->andReturn(['custom']);
    $registry = resolve(DatabasePlatformRegistry::class);
    $registry->register($platform);

    app()->forgetScopedInstances();

    expect(resolve(DatabasePlatformRegistry::class))->toBe($registry)
        ->and(resolve(DatabasePlatformRegistry::class)->forDriver('custom'))->toBe($platform);
});

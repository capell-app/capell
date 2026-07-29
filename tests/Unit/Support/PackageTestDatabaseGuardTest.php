<?php

declare(strict_types=1);

use Capell\Tests\Support\PackageTestDatabaseGuard;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;

it('blocks package tests from using Capell Ruby application databases', function (?string $database, ?string $url): void {
    expect(function () use ($database, $url): void {
        PackageTestDatabaseGuard::assertSafe('mysql', $database, $url, 'test');
    })
        ->toThrow(RuntimeException::class, 'Refusing to run Capell package Pest tests');
})->with([
    'shared app database' => ['capell_ruby', null],
    'app test database' => ['capell_ruby_test', null],
    'database url' => [null, 'mysql://root@127.0.0.1:3306/capell_ruby'],
]);

it('allows isolated package test databases', function (?string $connection, ?string $database, ?string $url): void {
    expect(function () use ($connection, $database, $url): void {
        PackageTestDatabaseGuard::assertSafe($connection, $database, $url, 'test');
    })
        ->not->toThrow(RuntimeException::class);
})->with([
    'sqlite memory' => ['sqlite', ':memory:', null],
    'package mysql database' => ['mysql', 'capell_package_test', null],
    'empty database' => ['sqlite', null, null],
]);

it('rejects server databases without a resolvable dedicated test name', function (?string $connection, ?string $database, ?string $url): void {
    expect(fn (): null => PackageTestDatabaseGuard::assertSafe($connection, $database, $url, 'test'))
        ->toThrow(RuntimeException::class, 'dedicated test');
})->with([
    'missing mysql database' => ['mysql', null, null],
    'missing postgresql database' => ['pgsql', null, null],
    'production name' => ['mysql', 'production', null],
    'test substring is not a test segment' => ['mariadb', 'capell_contest', null],
    'unsafe database URL' => ['postgresql', null, 'pgsql://root@127.0.0.1/production'],
]);

it('asserts the requested package test driver is the driver that resolved', function (): void {
    expect(fn (): null => PackageTestDatabaseGuard::assertRequestedDriverResolved('mysql', 'mysql'))
        ->not->toThrow(RuntimeException::class)
        ->and(fn (): null => PackageTestDatabaseGuard::assertRequestedDriverResolved('mariadb', 'mysql'))
        ->not->toThrow(RuntimeException::class)
        ->and(fn (): null => PackageTestDatabaseGuard::assertRequestedDriverResolved('mysql', 'sqlite'))
        ->toThrow(RuntimeException::class, 'Requested package test database driver [mysql] resolved to [sqlite].');
});

it('rejects an unsafe database name from the live resolved connection', function (): void {
    $config = Mockery::mock(Repository::class);
    $config->shouldReceive('get')->with('database.default')->andReturn('mysql');
    $config->shouldReceive('get')->with('database.connections.mysql.database')->andReturn('capell_packages_test');
    $config->shouldReceive('get')->with('database.connections.mysql.url')->andReturnNull();
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->twice()->andReturn('mysql');
    $connection->shouldReceive('getDatabaseName')->once()->andReturn('production');
    $database = Mockery::mock(DatabaseManager::class);
    $database->shouldReceive('connection')->once()->andReturn($connection);
    $app = Mockery::mock(Application::class);
    $app->shouldReceive('make')->with(Repository::class)->once()->andReturn($config);
    $app->shouldReceive('make')->with(DatabaseManager::class)->once()->andReturn($database);
    $originalConnection = getenv('DB_CONNECTION');
    putenv('DB_CONNECTION=mysql');

    try {
        expect(fn (): null => PackageTestDatabaseGuard::assertConfigurationIsSafe($app))
            ->toThrow(RuntimeException::class, 'database [production] without a dedicated test name');
    } finally {
        $originalConnection === false
            ? putenv('DB_CONNECTION')
            : putenv('DB_CONNECTION=' . $originalConnection);
    }
});

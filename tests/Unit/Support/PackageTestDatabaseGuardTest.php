<?php

declare(strict_types=1);

use Capell\Tests\Support\PackageTestDatabaseGuard;

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

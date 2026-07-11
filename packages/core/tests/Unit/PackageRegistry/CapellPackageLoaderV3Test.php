<?php

declare(strict_types=1);

use Capell\Core\Facades\CapellCore;
use Illuminate\Auth\AuthServiceProvider;
use Illuminate\Cache\CacheServiceProvider;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Illuminate\Hashing\HashServiceProvider;

it('does not register runtime capabilities for disabled non-core manifest v3 packages', function (): void {
    $registry = packageLoaderRegistry('vendor/disabled-package', [
        'metadata' => [AuthServiceProvider::class],
        'install' => [CacheServiceProvider::class],
        'runtime' => [FilesystemServiceProvider::class],
        'auth' => [HashServiceProvider::class],
    ]);

    CapellCore::shouldReceive('isPackageEnabled')->once()->with('vendor/disabled-package')->andReturnFalse();

    expect(packageLoader($registry)->collectProviders())
        ->toContain(AuthServiceProvider::class, CacheServiceProvider::class)
        ->not->toContain(FilesystemServiceProvider::class, HashServiceProvider::class);
});

it('registers runtime admin frontend and auth capabilities for enabled manifest v3 packages', function (): void {
    $registry = packageLoaderRegistry('vendor/enabled-package', [
        'runtime' => [AuthServiceProvider::class],
        'admin' => [CacheServiceProvider::class],
        'frontend' => [FilesystemServiceProvider::class],
        'auth' => [HashServiceProvider::class],
    ]);

    CapellCore::shouldReceive('isPackageEnabled')->once()->with('vendor/enabled-package')->andReturnTrue();

    expect(packageLoader($registry)->collectProviders())
        ->toContain(
            AuthServiceProvider::class,
            CacheServiceProvider::class,
            FilesystemServiceProvider::class,
            HashServiceProvider::class,
        );
});

it('registers every trusted core capability without runtime gate checks', function (): void {
    $registry = packageLoaderRegistry('capell-app/core', [
        'runtime' => [AuthServiceProvider::class],
        'admin' => [CacheServiceProvider::class],
        'frontend' => [FilesystemServiceProvider::class],
        'auth' => [HashServiceProvider::class],
    ]);

    CapellCore::shouldReceive('isPackageEnabled')->never();

    expect(packageLoader($registry)->collectProviders())
        ->toContain(
            AuthServiceProvider::class,
            CacheServiceProvider::class,
            FilesystemServiceProvider::class,
            HashServiceProvider::class,
        );
});

it('deduplicates capabilities declared for more than one request surface', function (): void {
    $registry = packageLoaderRegistry('vendor/shared-package', [
        'runtime' => [AuthServiceProvider::class],
        'admin' => [AuthServiceProvider::class],
        'frontend' => [AuthServiceProvider::class],
    ]);

    CapellCore::shouldReceive('isPackageEnabled')->once()->with('vendor/shared-package')->andReturnTrue();

    expect(packageLoader($registry)->collectProviders())
        ->toBe([AuthServiceProvider::class]);
});

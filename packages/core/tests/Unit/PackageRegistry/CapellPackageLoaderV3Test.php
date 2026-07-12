<?php

declare(strict_types=1);

use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\PackageRegistry\CapellPackageLoader;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Illuminate\Auth\AuthServiceProvider;
use Illuminate\Cache\CacheServiceProvider;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Illuminate\Hashing\HashServiceProvider;
use Mockery\MockInterface;

it('does not register runtime capabilities for disabled non-core manifest v3 packages', function (): void {
    $registry = packageLoaderV3Registry('vendor/disabled-package', [
        'metadata' => [AuthServiceProvider::class],
        'install' => [CacheServiceProvider::class],
        'runtime' => [FilesystemServiceProvider::class],
        'auth' => [HashServiceProvider::class],
    ]);

    CapellCore::shouldReceive('isPackageEnabled')->once()->with('vendor/disabled-package')->andReturnFalse();

    expect(packageV3Loader($registry)->collectProviders())
        ->toContain(AuthServiceProvider::class, CacheServiceProvider::class)
        ->not->toContain(FilesystemServiceProvider::class, HashServiceProvider::class);
});

it('registers runtime admin frontend and auth capabilities for enabled manifest v3 packages', function (): void {
    $registry = packageLoaderV3Registry('vendor/enabled-package', [
        'runtime' => [AuthServiceProvider::class],
        'admin' => [CacheServiceProvider::class],
        'frontend' => [FilesystemServiceProvider::class],
        'auth' => [HashServiceProvider::class],
    ]);

    CapellCore::shouldReceive('isPackageEnabled')->once()->with('vendor/enabled-package')->andReturnTrue();

    expect(packageV3Loader($registry)->collectProviders())
        ->toContain(
            AuthServiceProvider::class,
            CacheServiceProvider::class,
            FilesystemServiceProvider::class,
            HashServiceProvider::class,
        );
});

it('registers every trusted core capability without runtime gate checks', function (): void {
    $registry = packageLoaderV3Registry('capell-app/core', [
        'runtime' => [AuthServiceProvider::class],
        'admin' => [CacheServiceProvider::class],
        'frontend' => [FilesystemServiceProvider::class],
        'auth' => [HashServiceProvider::class],
    ]);

    CapellCore::shouldReceive('isPackageEnabled')->never();

    expect(packageV3Loader($registry)->collectProviders())
        ->toContain(
            AuthServiceProvider::class,
            CacheServiceProvider::class,
            FilesystemServiceProvider::class,
            HashServiceProvider::class,
        );
});

it('deduplicates capabilities declared for more than one request surface', function (): void {
    $registry = packageLoaderV3Registry('vendor/shared-package', [
        'runtime' => [AuthServiceProvider::class],
        'admin' => [AuthServiceProvider::class],
        'frontend' => [AuthServiceProvider::class],
    ]);

    CapellCore::shouldReceive('isPackageEnabled')->once()->with('vendor/shared-package')->andReturnTrue();

    expect(packageV3Loader($registry)->collectProviders())
        ->toBe([AuthServiceProvider::class]);
});

/** @param array<string, list<class-string>> $providers */
function packageLoaderV3Registry(string $name, array $providers): CapellPackageRegistry
{
    $registry = new CapellPackageRegistry;
    $registry->fill([
        $name => CapellManifestData::fromArray(capellManifestV3Array(
            name: $name,
            surfaces: ['admin', 'frontend'],
            providers: $providers,
        )),
    ]);

    return $registry;
}

function packageV3Loader(CapellPackageRegistry $registry): CapellPackageLoader
{
    /** @var Application&MockInterface $application */
    $application = Mockery::mock(Application::class);

    return new CapellPackageLoader($application, $registry);
}

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
use Mockery\MockInterface;

it('always includes metadata and install providers for discovered packages', function (): void {
    $registry = packageLoaderRegistry('capell-app/blog', [
        'metadata' => [AuthServiceProvider::class],
        'install' => [CacheServiceProvider::class],
        'runtime' => [FilesystemServiceProvider::class],
    ]);

    CapellCore::shouldReceive('isPackageEnabled')->once()->with('capell-app/blog')->andReturnFalse();

    $providers = packageLoader($registry)->collectProviders();

    expect($providers)->toContain(AuthServiceProvider::class)
        ->and($providers)->toContain(CacheServiceProvider::class)
        ->and($providers)->not->toContain(FilesystemServiceProvider::class);
});

it('registers every runtime capability for an enabled package at worker boot', function (): void {
    $registry = packageLoaderRegistry('capell-app/blog', [
        'runtime' => [AuthServiceProvider::class],
        'admin' => [CacheServiceProvider::class],
        'frontend' => [FilesystemServiceProvider::class],
    ]);

    CapellCore::shouldReceive('isPackageEnabled')->once()->with('capell-app/blog')->andReturnTrue();

    expect(packageLoader($registry)->collectProviders())
        ->toContain(AuthServiceProvider::class, CacheServiceProvider::class, FilesystemServiceProvider::class);
});

it('does not freeze provider capabilities to the first request context', function (): void {
    $registry = packageLoaderRegistry('capell-app/blog', [
        'admin' => [CacheServiceProvider::class],
        'frontend' => [FilesystemServiceProvider::class],
    ]);

    CapellCore::shouldReceive('isPackageEnabled')->twice()->with('capell-app/blog')->andReturnTrue();

    $loader = packageLoader($registry);

    expect($loader->collectProviders())->toContain(CacheServiceProvider::class, FilesystemServiceProvider::class)
        ->and($loader->collectProviders())->toContain(CacheServiceProvider::class, FilesystemServiceProvider::class);
});

it('loads all capabilities for trusted core packages without lifecycle checks', function (): void {
    $registry = packageLoaderRegistry('capell-app/core', [
        'runtime' => [AuthServiceProvider::class],
        'admin' => [CacheServiceProvider::class],
        'frontend' => [FilesystemServiceProvider::class],
    ]);

    CapellCore::shouldReceive('isPackageEnabled')->never();

    expect(packageLoader($registry)->collectProviders())
        ->toContain(AuthServiceProvider::class, CacheServiceProvider::class, FilesystemServiceProvider::class);
});

it('skips providers for non-existent classes gracefully', function (): void {
    $registry = packageLoaderRegistry('capell-app/ghost', [
        'admin' => ['Capell\\Ghost\\Providers\\NonExistentProvider'],
    ]);

    CapellCore::shouldReceive('isPackageEnabled')->once()->with('capell-app/ghost')->andReturnTrue();

    expect(fn () => packageLoader($registry)->loadProviders())->not->toThrow(Throwable::class);
});

it('quarantines an optional package when provider registration fails', function (): void {
    $registry = packageLoaderRegistry('vendor/failing-extension', [
        'runtime' => [AuthServiceProvider::class],
    ]);

    /** @var Application&MockInterface $application */
    $application = Mockery::mock(Application::class);
    $application->shouldReceive('register')
        ->once()
        ->with(AuthServiceProvider::class)
        ->andThrow(new RuntimeException('provider registration failed'));

    CapellCore::shouldReceive('isPackageEnabled')->once()->with('vendor/failing-extension')->andReturnTrue();
    CapellCore::shouldReceive('markPackageProviderQuarantined')
        ->once()
        ->with('vendor/failing-extension', AuthServiceProvider::class, Mockery::type('string'));

    expect(fn (): mixed => (new CapellPackageLoader($application, $registry))->loadProviders())
        ->not->toThrow(Throwable::class);
});

it('does not quarantine trusted core packages when provider registration fails', function (): void {
    $registry = packageLoaderRegistry('capell-app/core', [
        'runtime' => [AuthServiceProvider::class],
    ]);

    /** @var Application&MockInterface $application */
    $application = Mockery::mock(Application::class);
    $application->shouldReceive('register')
        ->once()
        ->with(AuthServiceProvider::class)
        ->andThrow(new RuntimeException('core provider registration failed'));

    CapellCore::shouldReceive('markPackageProviderQuarantined')->never();

    expect(fn (): mixed => (new CapellPackageLoader($application, $registry))->loadProviders())
        ->toThrow(RuntimeException::class, 'core provider registration failed');
});

/** @param array<string, list<class-string>> $providers */
function packageLoaderRegistry(string $name, array $providers): CapellPackageRegistry
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

function packageLoader(CapellPackageRegistry $registry): CapellPackageLoader
{
    /** @var Application&MockInterface $application */
    $application = Mockery::mock(Application::class);

    return new CapellPackageLoader($application, $registry);
}

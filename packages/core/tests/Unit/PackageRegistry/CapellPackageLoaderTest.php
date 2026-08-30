<?php

declare(strict_types=1);

use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Extensions\ExtensionContributionReceiptRegistry;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\PackageRegistry\CapellPackageLoader;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Capell\Core\Support\Packages\PackageSurfaceRegistrar;
use Illuminate\Auth\AuthServiceProvider;
use Illuminate\Cache\CacheServiceProvider;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Illuminate\Support\ServiceProvider;
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

    expect(fn (): array => packageLoader($registry)->loadProviders())->not->toThrow(Throwable::class);
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

    expect(function () use ($application, $registry): void {
        new CapellPackageLoader($application, $registry, receipts: new ExtensionContributionReceiptRegistry)->loadProviders();
    })
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

    expect(function () use ($application, $registry): void {
        new CapellPackageLoader($application, $registry, receipts: new ExtensionContributionReceiptRegistry)->loadProviders();
    })
        ->toThrow(RuntimeException::class, 'core provider registration failed');
});

it('keeps an extension receipt owner while a registered provider boots', function (): void {
    $registry = packageLoaderRegistry('vendor/boot-receipt', [
        'runtime' => [BootingReceiptTestProvider::class],
    ]);
    $receipts = new ExtensionContributionReceiptRegistry;
    app()->instance(ExtensionContributionReceiptRegistry::class, $receipts);

    CapellCore::shouldReceive('isPackageEnabled')->once()->with('vendor/boot-receipt')->andReturnTrue();

    new CapellPackageLoader(app(), $registry, receipts: $receipts)->loadProviders();

    expect($receipts->forPackage('vendor/boot-receipt'))
        ->toHaveCount(1)
        ->and($receipts->forPackage('vendor/boot-receipt')[0]->providerBucket)->toBe('runtime')
        ->and($receipts->forPackage('vendor/boot-receipt')[0]->foundationBuiltIn)->toBeFalse();
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

    return new CapellPackageLoader(
        $application,
        $registry,
        receipts: new ExtensionContributionReceiptRegistry,
    );
}

final class BootingReceiptTestProvider extends ServiceProvider
{
    public function boot(): void
    {
        resolve(PackageSurfaceRegistrar::class)->models([stdClass::class]);
    }
}

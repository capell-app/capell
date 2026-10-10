<?php

declare(strict_types=1);

use Capell\Core\Enums\ExtensionProviderRecoveryStateEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\CapellExtension;
use Capell\Core\Support\Extensions\ExtensionContributionReceiptRegistry;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\PackageRegistry\CapellPackageLoader;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Capell\Core\Support\Packages\InstalledRuntimeLifecycle;
use Capell\Core\Tests\Support\BootingReceiptTestProvider;
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

    CapellCore::registerPackage('capell-app/blog');
    CapellCore::markPackageDisabled('capell-app/blog');

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

    CapellCore::registerPackage('capell-app/blog');
    CapellCore::markPackageInstalled('capell-app/blog');

    expect(packageLoader($registry)->collectProviders())
        ->toContain(AuthServiceProvider::class, CacheServiceProvider::class, FilesystemServiceProvider::class);
});

it('does not freeze provider capabilities to the first request context', function (): void {
    $registry = packageLoaderRegistry('capell-app/blog', [
        'admin' => [CacheServiceProvider::class],
        'frontend' => [FilesystemServiceProvider::class],
    ]);

    CapellCore::registerPackage('capell-app/blog');
    CapellCore::markPackageInstalled('capell-app/blog');

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

    expect(packageLoader($registry)->collectProviders())
        ->toContain(AuthServiceProvider::class, CacheServiceProvider::class, FilesystemServiceProvider::class);
});

it('skips providers for non-existent classes gracefully', function (): void {
    $registry = packageLoaderRegistry('capell-app/ghost', [
        'admin' => ['Capell\\Ghost\\Providers\\NonExistentProvider'],
    ]);

    CapellCore::registerPackage('capell-app/ghost');
    CapellCore::markPackageInstalled('capell-app/ghost');

    expect(fn (): array => packageLoader($registry)->loadProviders())->not->toThrow(Throwable::class);
});

it('quarantines an optional package when provider registration fails', function (): void {
    $registry = packageLoaderRegistry('vendor/failing-extension', [
        'runtime' => [AuthServiceProvider::class],
    ]);

    /** @var Application&MockInterface $application */
    $application = Mockery::mock(Application::class);
    $application->shouldReceive('make')->with(InstalledRuntimeLifecycle::class)->andReturn(new InstalledRuntimeLifecycle($application));
    $application->shouldReceive('isBooted')->andReturnFalse();
    $application->shouldReceive('register')

        ->with(AuthServiceProvider::class)
        ->andThrow(new RuntimeException('provider registration failed'));
    $application->shouldReceive('resolved')->with(InstalledRuntimeLifecycle::class)->andReturnFalse();

    CapellCore::registerPackage('vendor/failing-extension');
    CapellCore::markPackageInstalled('vendor/failing-extension');

    expect(function () use ($application, $registry): void {
        new CapellPackageLoader($application, $registry, receipts: new ExtensionContributionReceiptRegistry)->loadProviders();
    })
        ->not->toThrow(Throwable::class);

    $extension = CapellExtension::query()->where('composer_name', 'vendor/failing-extension')->firstOrFail();
    expect($extension->provider_recovery_state)->toBe(ExtensionProviderRecoveryStateEnum::Quarantined)
        ->and($extension->provider_recovery_reason)->toContain('failed during registration')
        ->and(CapellCore::isPackageEnabled('vendor/failing-extension'))->toBeFalse();
});

it('does not quarantine trusted core packages when provider registration fails', function (): void {
    $registry = packageLoaderRegistry('capell-app/core', [
        'runtime' => [AuthServiceProvider::class],
    ]);

    /** @var Application&MockInterface $application */
    $application = Mockery::mock(Application::class);
    $application->shouldReceive('make')->with(InstalledRuntimeLifecycle::class)->andReturn(new InstalledRuntimeLifecycle($application));
    $application->shouldReceive('isBooted')->andReturnFalse();
    $application->shouldReceive('register')

        ->with(AuthServiceProvider::class)
        ->andThrow(new RuntimeException('core provider registration failed'));

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

    CapellCore::registerPackage('vendor/boot-receipt');
    CapellCore::markPackageInstalled('vendor/boot-receipt');

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
    return new CapellPackageLoader(app(), $registry, receipts: new ExtensionContributionReceiptRegistry);
}

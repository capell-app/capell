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
use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
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

    packageLoader($registry)->loadProviders();

    expect(CapellCore::isPackageInstalled('capell-app/ghost'))->toBeTrue();
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

    new CapellPackageLoader($application, $registry, receipts: new ExtensionContributionReceiptRegistry)->loadProviders();

    $extension = CapellExtension::query()->where('composer_name', 'vendor/failing-extension')->firstOrFail();
    expect($extension->provider_recovery_state)->toBe(ExtensionProviderRecoveryStateEnum::Quarantined)
        ->and($extension->provider_recovery_reason)->toContain('failed during registration')
        ->and(CapellCore::isPackageEnabled('vendor/failing-extension'))->toBeFalse();
});

it('keeps booting and quarantines in memory when a provider fails while the database is unreachable', function (): void {
    $registry = packageLoaderRegistry('vendor/offline-failing-extension', [
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

    CapellCore::registerPackage('vendor/offline-failing-extension');
    CapellCore::markPackageInstalled('vendor/offline-failing-extension');

    Schema::shouldReceive('hasTable')->andThrow(new RuntimeException('database unavailable'));

    new CapellPackageLoader($application, $registry, receipts: new ExtensionContributionReceiptRegistry)->loadProviders();

    expect(CapellCore::isPackageInstalled('vendor/offline-failing-extension'))->toBeFalse();
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

it('rolls back earlier provider bindings when a later provider of the same package is quarantined', function (): void {
    app()->instance('binding-from-before-the-package', 'kept');

    $earlier = new class(app()) extends ServiceProvider
    {
        #[Override]
        public function register(): void
        {
            $this->app->instance('quarantined-package-binding', new stdClass);
            $this->app->singleton('quarantined-package-singleton', static fn (): stdClass => new stdClass);
            $this->app->alias('quarantined-package-binding', 'quarantined-package-alias');
        }
    };
    $later = new class(app()) extends ServiceProvider
    {
        #[Override]
        public function register(): void
        {
            throw new RuntimeException('later provider failed');
        }
    };
    $name = 'vendor/partial-registration';
    CapellCore::registerPackage($name);
    CapellCore::markPackageInstalled($name);

    $loaded = packageLoader(packageLoaderRegistry($name, ['runtime' => [$earlier::class, $later::class]]))->loadProviders();

    expect(CapellCore::isPackageEnabled($name))->toBeFalse()
        ->and(app()->bound('quarantined-package-binding'))->toBeFalse()
        ->and(app()->bound('quarantined-package-singleton'))->toBeFalse()
        ->and(app()->bound('quarantined-package-alias'))->toBeFalse()
        ->and(app()->getProvider($earlier::class))->toBeNull()
        ->and($loaded)->toBe([])
        ->and(resolve('binding-from-before-the-package'))->toBe('kept');
});

it('never boots an earlier provider of a package whose later provider is quarantined', function (): void {
    // Package providers load before the application boots, so the rollback runs on an unbooted container.
    $testApplication = app();
    $application = new Illuminate\Foundation\Application(base_path());
    Container::setInstance($testApplication);
    $bootLog = new ArrayObject;
    $application->instance('provider-rollback-boot-log', $bootLog);

    $earlier = new class($application) extends ServiceProvider
    {
        public function boot(): void
        {
            $this->app->make('provider-rollback-boot-log')->append('earlier booted');
        }
    };
    $later = new class(app()) extends ServiceProvider
    {
        #[Override]
        public function register(): void
        {
            throw new RuntimeException('later provider failed');
        }
    };
    $name = 'vendor/partial-boot';
    CapellCore::registerPackage($name);
    CapellCore::markPackageInstalled($name);

    new CapellPackageLoader($application, packageLoaderRegistry($name, ['runtime' => [$earlier::class, $later::class]]), receipts: new ExtensionContributionReceiptRegistry)->loadProviders();
    $application->boot();

    expect($bootLog->getArrayCopy())->toBe([]);
});

it('keeps the providers of a healthy package when another package is quarantined', function (): void {
    $healthy = new class(app()) extends ServiceProvider
    {
        #[Override]
        public function register(): void
        {
            $this->app->instance('healthy-package-binding', 'present');
        }
    };
    $failing = new class(app()) extends ServiceProvider
    {
        #[Override]
        public function register(): void
        {
            throw new RuntimeException('provider failed');
        }
    };
    $registry = new CapellPackageRegistry;
    $registry->fill([
        'vendor/healthy' => CapellManifestData::fromArray(capellManifestV3Array(name: 'vendor/healthy', surfaces: ['admin', 'frontend'], providers: ['runtime' => [$healthy::class]])),
        'vendor/failing' => CapellManifestData::fromArray(capellManifestV3Array(name: 'vendor/failing', surfaces: ['admin', 'frontend'], providers: ['runtime' => [$failing::class]])),
    ]);

    foreach (['vendor/healthy', 'vendor/failing'] as $name) {
        CapellCore::registerPackage($name);
        CapellCore::markPackageInstalled($name);
    }

    $loaded = packageLoader($registry)->loadProviders();

    expect($loaded)->toBe([$healthy::class])
        ->and(resolve('healthy-package-binding'))->toBe('present');
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

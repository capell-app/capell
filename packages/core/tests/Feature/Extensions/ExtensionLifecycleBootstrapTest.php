<?php

declare(strict_types=1);

use Capell\Core\Enums\ExtensionProviderRecoveryStateEnum;
use Capell\Core\Enums\ExtensionStatusEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\CapellExtension;
use Capell\Core\Support\Bootstrap\PackageRegistryBootstrapper;
use Capell\Core\Support\Extensions\ExtensionLifecycleRepository;
use Capell\Core\Tests\Support\ManifestBootstrapFrontendProvider;
use Capell\Core\Tests\Support\ManifestBootstrapMetadataProvider;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\File;
use Spatie\EventSourcing\StoredEvents\EventSubscriber;

it('reads persisted lifecycle gates at cold bootstrap without reaching a flushed application', function (
    ExtensionStatusEnum $status,
    ExtensionProviderRecoveryStateEnum $recovery,
    bool $paid,
    bool $runtimeAllowed,
    bool $enabled,
    bool $staleResolver,
): void {
    withExtensionLifecycleBootstrapFixture(function (string $packageName, PackageRegistryBootstrapper $bootstrapper) use (
        $status,
        $recovery,
        $paid,
        $runtimeAllowed,
        $enabled,
        $staleResolver,
    ): void {
        CapellExtension::query()->create([
            'composer_name' => $packageName,
            'status' => $status,
            'provider_recovery_state' => $recovery,
            'is_paid_marketplace_extension' => $paid,
            'marketplace_runtime_status' => $paid ? 'active' : null,
            'marketplace_runtime_allowed' => $runtimeAllowed,
        ]);

        $application = app();
        $database = $application->make(ConnectionResolverInterface::class);
        $events = $application->make(Dispatcher::class);

        for ($bootstrap = 0; $bootstrap < 2; $bootstrap++) {
            retainFlushedExtensionLifecycleModelContext($application, $staleResolver);

            $bootstrapper->bootstrap();

            expect(resolve(ExtensionLifecycleRepository::class)->status($packageName, CapellCore::getPackages(withoutCore: false)))
                ->toBe($status)
                ->and(CapellCore::isPackageEnabled($packageName))->toBe($enabled)
                ->and($application->make('manifest-metadata-registered'))->toBeTrue()
                ->and($application->providerIsLoaded(ManifestBootstrapFrontendProvider::class))->toBe($enabled)
                ->and($application->bound('manifest-frontend-registered'))->toBe($enabled)
                ->and(Model::getConnectionResolver())->toBe($database)
                ->and(Model::getEventDispatcher())->toBe($events);
        }
    });
})->with([
    'enabled' => [ExtensionStatusEnum::Enabled, ExtensionProviderRecoveryStateEnum::Healthy, false, true, true],
    'disabled' => [ExtensionStatusEnum::Disabled, ExtensionProviderRecoveryStateEnum::Healthy, false, true, false],
    'failed' => [ExtensionStatusEnum::Failed, ExtensionProviderRecoveryStateEnum::Healthy, false, true, false],
    'uninstalled' => [ExtensionStatusEnum::Uninstalled, ExtensionProviderRecoveryStateEnum::Healthy, false, true, false],
    'quarantined' => [ExtensionStatusEnum::Enabled, ExtensionProviderRecoveryStateEnum::Quarantined, false, true, false],
    'marketplace disallowed' => [ExtensionStatusEnum::Enabled, ExtensionProviderRecoveryStateEnum::Healthy, true, false, false],
])->with([
    'retained resolver and dispatcher' => true,
    'retained dispatcher only' => false,
]);

it('loads a newly enabled package after an earlier cold bootstrap found no lifecycle record', function (): void {
    withExtensionLifecycleBootstrapFixture(function (string $packageName, PackageRegistryBootstrapper $bootstrapper): void {
        $application = app();

        retainFlushedExtensionLifecycleModelContext($application, true);
        $bootstrapper->bootstrap();

        expect(CapellCore::isPackageEnabled($packageName))->toBeFalse()
            ->and($application->providerIsLoaded(ManifestBootstrapFrontendProvider::class))->toBeFalse();

        CapellExtension::query()->create([
            'composer_name' => $packageName,
            'status' => ExtensionStatusEnum::Enabled,
        ]);

        retainFlushedExtensionLifecycleModelContext($application, true);
        $bootstrapper->bootstrap();

        expect(CapellCore::isPackageEnabled($packageName))->toBeTrue()
            ->and($application->providerIsLoaded(ManifestBootstrapFrontendProvider::class))->toBeTrue()
            ->and($application->make('manifest-frontend-registered'))->toBeTrue();
    });
});

it('preserves intentional model event suppression during a warm package bootstrap', function (): void {
    withExtensionLifecycleBootstrapFixture(function (string $packageName, PackageRegistryBootstrapper $bootstrapper): void {
        CapellExtension::query()->create([
            'composer_name' => $packageName,
            'status' => ExtensionStatusEnum::Enabled,
        ]);

        Model::withoutEvents(function () use ($packageName, $bootstrapper): void {
            $suppressedEvents = Model::getEventDispatcher();

            $bootstrapper->bootstrap();

            expect(CapellCore::isPackageEnabled($packageName))->toBeTrue()
                ->and(app()->make('manifest-frontend-registered'))->toBeTrue()
                ->and(Model::getEventDispatcher())->toBe($suppressedEvents);
        });
    }, cold: false);
});

/** @param Closure(string, PackageRegistryBootstrapper): void $callback */
function withExtensionLifecycleBootstrapFixture(Closure $callback, bool $cold = true): void
{
    $application = app();
    $bootstrapPath = storage_path('framework/testing/lifecycle-bootstrap-' . bin2hex(random_bytes(6)));
    $originalBootstrapPath = $application->bootstrapPath();
    $booted = new ReflectionProperty($application, 'booted');
    $originalBooted = $booted->getValue($application);
    $database = Model::getConnectionResolver();
    $events = Model::getEventDispatcher();
    $packageName = 'vendor/cold-lifecycle-extension';

    File::ensureDirectoryExists($bootstrapPath . '/cache');

    try {
        file_put_contents(
            $bootstrapPath . '/cache/capell-package-manifests.php',
            '<?php return ' . var_export([
                $packageName => capellManifestV3Array(
                    name: $packageName,
                    surfaces: ['frontend'],
                    providers: [
                        'metadata' => [ManifestBootstrapMetadataProvider::class],
                        'frontend' => [ManifestBootstrapFrontendProvider::class],
                    ],
                ),
            ], return: true) . ';',
        );
        $application->useBootstrapPath($bootstrapPath);
        $booted->setValue($application, ! $cold);

        $callback($packageName, resolve(PackageRegistryBootstrapper::class));
    } finally {
        Container::setInstance($application);
        $application->useBootstrapPath($originalBootstrapPath);
        $booted->setValue($application, $originalBooted);
        if ($database !== null) {
            Model::setConnectionResolver($database);
        }

        if ($events !== null) {
            Model::setEventDispatcher($events);
        }

        File::deleteDirectory($bootstrapPath);
    }
}

function retainFlushedExtensionLifecycleModelContext(Application $application, bool $staleResolver): void
{
    $database = $application->make(ConnectionResolverInterface::class);
    $previous = new Application($application->basePath());

    try {
        $previous->instance('config', clone $application->make(Repository::class));
        $provider = new DatabaseServiceProvider($previous);
        $previous->register($provider);
        $provider->boot();
        $previous->make(Dispatcher::class)->listen('*', EventSubscriber::class . '@handle');
        $previous->flush();

        if (! $staleResolver) {
            Model::setConnectionResolver($database);
        }
    } finally {
        Container::setInstance($application);
    }
}

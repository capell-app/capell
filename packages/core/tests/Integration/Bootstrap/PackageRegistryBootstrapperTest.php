<?php

declare(strict_types=1);

use Capell\Core\Enums\ExtensionStatusEnum;
use Capell\Core\Enums\RuntimeRole;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\CapellExtension;
use Capell\Core\Support\Bootstrap\PackageRegistryBootstrapper;
use Capell\Core\Support\Extensions\ExtensionContributionReceiptRegistry;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Capell\Core\Support\Runtime\RuntimeRoleResolver;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;

it('registers a manifest-only frontend provider on web requests only when its non-core package is enabled', function (RuntimeRole $role, ExtensionStatusEnum $status): void {
    $bootstrapPath = storage_path('framework/testing/manifest-provider-bootstrap-' . bin2hex(random_bytes(6)));
    File::ensureDirectoryExists($bootstrapPath . '/cache');
    $originalBootstrapPath = $this->app->bootstrapPath();
    $originalEnvironment = $this->app->environment();
    $originalRole = Env::get('CAPELL_RUNTIME_ROLE');
    $console = new ReflectionProperty($this->app, 'isRunningInConsole');
    $originalConsole = $console->getValue($this->app);
    $packageName = 'vendor/manifest-only-frontend';
    $siblingName = 'vendor/manifest-metadata';
    $enabled = $status === ExtensionStatusEnum::Enabled;

    try {
        file_put_contents(
            $bootstrapPath . '/cache/capell-package-manifests.php',
            '<?php return ' . var_export([
                $siblingName => capellManifestV3Array(
                    name: $siblingName,
                    providers: ['metadata' => [ManifestBootstrapMetadataProvider::class]],
                ),
                $packageName => capellManifestV3Array(
                    name: $packageName,
                    surfaces: ['frontend'],
                    providers: ['frontend' => [ManifestBootstrapFrontendProvider::class]],
                ),
            ], return: true) . ';',
        );

        // Persist lifecycle state before discovery, without registering a legacy provider.
        CapellExtension::query()->create([
            'composer_name' => $packageName,
            'status' => $status,
        ]);
        CapellCore::clearExtensionCache();

        expect(CapellCore::hasPackage($packageName))->toBeFalse()
            ->and($this->app->providerIsLoaded(ManifestBootstrapFrontendProvider::class))->toBeFalse()
            ->and($this->app->providerIsLoaded(ManifestBootstrapMetadataProvider::class))->toBeFalse();

        $this->app->useBootstrapPath($bootstrapPath);
        $this->app->instance('env', 'production');
        $console->setValue($this->app, false);
        Env::getRepository()->set('CAPELL_RUNTIME_ROLE', $role->value);

        expect($this->app->runningInConsole())->toBeFalse()
            ->and(RuntimeRoleResolver::fromEnvironment()->role())->toBe($role);

        resolve(PackageRegistryBootstrapper::class)->bootstrap();

        $registry = resolve(CapellPackageRegistry::class);
        $package = CapellCore::getPackage($packageName);

        expect($registry->has($packageName))->toBeTrue()
            ->and($package->isCore())->toBeFalse()
            ->and($package->manifest?->providers->runtime)->toBe([])
            ->and($package->manifest?->providers->frontend)->toBe([ManifestBootstrapFrontendProvider::class])
            ->and(CapellCore::isPackageEnabled($packageName))->toBe($enabled)
            ->and($this->app->providerIsLoaded(ManifestBootstrapMetadataProvider::class))->toBeTrue()
            ->and($this->app->make('manifest-metadata-registered'))->toBeTrue()
            ->and($this->app->providerIsLoaded(ManifestBootstrapFrontendProvider::class))->toBe($enabled)
            ->and($this->app->bound('manifest-frontend-registered'))->toBe($enabled)
            ->and(resolve(ExtensionContributionReceiptRegistry::class)->loadedBuckets($packageName))->toBe($enabled ? ['frontend'] : []);

        if ($enabled) {
            expect($this->app->make('manifest-frontend-registered'))->toBeTrue();
        }
    } finally {
        $this->app->useBootstrapPath($originalBootstrapPath);
        $this->app->instance('env', $originalEnvironment);
        $console->setValue($this->app, $originalConsole);
        if ($originalRole === null) {
            Env::getRepository()->clear('CAPELL_RUNTIME_ROLE');
        } else {
            Env::getRepository()->set('CAPELL_RUNTIME_ROLE', (string) $originalRole);
        }

        File::deleteDirectory($bootstrapPath);
    }
})->with([
    'combined enabled' => [RuntimeRole::Combined, ExtensionStatusEnum::Enabled],
    'public enabled' => [RuntimeRole::Public, ExtensionStatusEnum::Enabled],
    'combined disabled' => [RuntimeRole::Combined, ExtensionStatusEnum::Disabled],
    'public disabled' => [RuntimeRole::Public, ExtensionStatusEnum::Disabled],
]);

final class ManifestBootstrapMetadataProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->app->instance('manifest-metadata-registered', true);
    }
}

final class ManifestBootstrapFrontendProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->app->instance('manifest-frontend-registered', true);
    }
}

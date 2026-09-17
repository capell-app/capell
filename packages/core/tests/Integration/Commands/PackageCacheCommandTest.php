<?php

declare(strict_types=1);

use Capell\Core\Actions\BuildPackageCacheAction;
use Capell\Core\Enums\RuntimeRole;
use Capell\Core\Models\Theme;
use Capell\Core\Support\Bootstrap\PackageRegistryBootstrapper;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Capell\Core\Support\Runtime\RuntimeRoleCachePaths;
use Capell\Core\Support\Runtime\RuntimeRoleProviderPolicy;
use Capell\Frontend\Support\View\ThemeChainResolver;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

$packageCacheBootstrapPaths = new WeakMap;

beforeEach(function () use ($packageCacheBootstrapPaths): void {
    $originalBootstrapPath = $this->app->bootstrapPath();
    $isolatedBootstrapPath = storage_path('framework/testing/package-cache-bootstrap-' . bin2hex(random_bytes(6)));

    File::ensureDirectoryExists($isolatedBootstrapPath . '/cache');
    $this->app->useBootstrapPath($isolatedBootstrapPath);
    $packageCacheBootstrapPaths[$this] = [$originalBootstrapPath, $isolatedBootstrapPath];
});

afterEach(function () use ($packageCacheBootstrapPaths): void {
    [$originalBootstrapPath, $isolatedBootstrapPath] = $packageCacheBootstrapPaths[$this];

    $this->app->useBootstrapPath($originalBootstrapPath);
    File::deleteDirectory($isolatedBootstrapPath);
    unset($packageCacheBootstrapPaths[$this]);
});

it('capell:package-cache writes package and theme chain cache files', function (): void {
    $packageCachePath = $this->app->bootstrapPath('cache/capell-package-manifests.php');
    $themeCachePath = $this->app->bootstrapPath('cache/capell-theme-chain.php');
    $localAppThemeCachePath = $this->app->bootstrapPath('cache/capell-local-app-themes.php');
    $runtimePaths = resolve(RuntimeRoleCachePaths::class);

    Artisan::call('capell:package-cache');

    expect(file_exists($packageCachePath))->toBeTrue()
        ->and(file_exists($themeCachePath))->toBeTrue()
        ->and(file_exists($localAppThemeCachePath))->toBeTrue()
        ->and(file_exists($runtimePaths->metadata()))->toBeTrue();

    foreach (RuntimeRole::deploymentRoles() as $role) {
        expect(file_exists($runtimePaths->packages($role)))->toBeTrue()
            ->and(file_exists($runtimePaths->providers($role)))->toBeTrue()
            ->and(file_exists($runtimePaths->services($role)))->toBeTrue();
    }

    $publicPackages = require $runtimePaths->packages(RuntimeRole::Public);
    $publicServices = require $runtimePaths->services(RuntimeRole::Public);
    $policy = new RuntimeRoleProviderPolicy;
    $publicProviders = $publicServices['providers'];

    expect(array_values(array_intersect(array_keys($publicPackages), [
        'capell-app/admin',
        'capell-app/installer',
        'capell-app/marketplace',
    ])))->toBe([])
        ->and(array_filter(
            $publicProviders,
            $policy->isAuthoringProvider(...),
        ))->toBe([]);

    $packages = require $packageCachePath;
    $chain = require $themeCachePath;

    expect($packages)->toBeArray();

    foreach ($packages as $key => $manifest) {
        expect($key)->toBeString();
        expect($manifest)->toBeArray();
    }

    foreach ($chain as $key => $paths) {
        expect($key)->toBeString();
        expect($paths)->toBeArray();
    }
});

it('recovers from leaked placeholder cache content during the next bootstrap', function (): void {
    $cachePaths = [
        $this->app->bootstrapPath('cache/capell-package-manifests.php'),
        $this->app->bootstrapPath('cache/capell-theme-chain.php'),
        $this->app->bootstrapPath('cache/capell-local-app-themes.php'),
    ];

    foreach ($cachePaths as $cachePath) {
        file_put_contents($cachePath, '<?php return [];');
    }

    $runtimeServicesPath = $this->app->bootstrapPath('cache/capell-runtime/public/services.php');
    File::ensureDirectoryExists(dirname($runtimeServicesPath));
    file_put_contents($runtimeServicesPath, '<?php return [];');

    expect(Artisan::call('capell:package-cache'))->toBe(0);

    $registry = resolve(CapellPackageRegistry::class);
    $registry->clear();

    resolve(PackageRegistryBootstrapper::class)->bootstrap();

    expect($registry->has('capell-app/core'))->toBeTrue()
        ->and(file_get_contents($cachePaths[0]))->not->toBe('<?php return [];');
});

it('PackageRegistryBootstrapper uses capell-package-manifests.php when present', function (): void {
    $cachePath = $this->app->bootstrapPath('cache/capell-package-manifests.php');

    file_put_contents(
        $cachePath,
        '<?php return ' . var_export([
            'vendor/cached-package' => capellManifestV3Array(
                name: 'vendor/cached-package',
                surfaces: ['frontend'],
            ),
        ], return: true) . ';',
    );

    resolve(PackageRegistryBootstrapper::class)->bootstrap();

    $registry = resolve(CapellPackageRegistry::class);

    @unlink($cachePath);

    expect($registry->has('vendor/cached-package'))->toBeTrue();
});

it('PackageRegistryBootstrapper preserves packages registered before core boots', function (): void {
    $registry = resolve(CapellPackageRegistry::class);
    $registry->registerPackage('vendor/early-package');

    resolve(PackageRegistryBootstrapper::class)->bootstrap();

    expect(resolve(CapellPackageRegistry::class))->toBe($registry)
        ->and($registry->getPackage('vendor/early-package')->name)->toBe('vendor/early-package');
});

it('ThemeChainResolver uses capell-theme-chain.php when present', function (): void {
    $cachePath = $this->app->bootstrapPath('cache/capell-theme-chain.php');

    file_put_contents($cachePath, '<?php return ["default" => ["/fake/views"]];');

    $registry = resolve(CapellPackageRegistry::class);
    $resolver = new ThemeChainResolver($registry, cachePath: $cachePath);

    $theme = Theme::factory()->make(['key' => 'default']);
    $paths = $resolver->resolve($theme);

    @unlink($cachePath);

    expect($paths)->toBe(['/fake/views']);
});

it('capell:package-cache:clear with --only-clear removes cache files and does not rebuild', function (): void {
    $packageCachePath = $this->app->bootstrapPath('cache/capell-package-manifests.php');
    $themeCachePath = $this->app->bootstrapPath('cache/capell-theme-chain.php');
    $localAppThemeCachePath = $this->app->bootstrapPath('cache/capell-local-app-themes.php');
    $runtimeCachePath = $this->app->bootstrapPath('cache/capell-runtime');

    file_put_contents($packageCachePath, '<?php return [];');
    file_put_contents($themeCachePath, '<?php return [];');
    file_put_contents($localAppThemeCachePath, '<?php return [];');
    File::ensureDirectoryExists($runtimeCachePath . '/public');
    file_put_contents($runtimeCachePath . '/public/services.php', '<?php return [];');

    Artisan::call('capell:package-cache:clear', ['--only-clear' => true]);

    expect(file_exists($packageCachePath))->toBeFalse()
        ->and(file_exists($themeCachePath))->toBeFalse()
        ->and(file_exists($localAppThemeCachePath))->toBeFalse()
        ->and(file_exists($runtimeCachePath))->toBeFalse();
});

it('capell:package-cache:clear without --only-clear rebuilds immediately, never leaving the cache missing', function (): void {
    $packageCachePath = $this->app->bootstrapPath('cache/capell-package-manifests.php');
    $themeCachePath = $this->app->bootstrapPath('cache/capell-theme-chain.php');
    $localAppThemeCachePath = $this->app->bootstrapPath('cache/capell-local-app-themes.php');
    $runtimeCachePath = $this->app->bootstrapPath('cache/capell-runtime');

    file_put_contents($packageCachePath, '<?php return [];');
    file_put_contents($themeCachePath, '<?php return [];');
    file_put_contents($localAppThemeCachePath, '<?php return [];');
    File::ensureDirectoryExists($runtimeCachePath . '/public');
    file_put_contents($runtimeCachePath . '/public/services.php', '<?php return [];');

    expect(Artisan::call('capell:package-cache:clear'))->toBe(0);

    // Not the stale placeholder content written above — the real rebuild ran.
    expect(file_exists($packageCachePath))->toBeTrue()
        ->and(file_get_contents($packageCachePath))->not->toBe('<?php return [];')
        ->and(file_exists($themeCachePath))->toBeTrue()
        ->and(file_exists($localAppThemeCachePath))->toBeTrue();
});

it('capell:package-cache:clear succeeds when cache files are already absent', function (): void {
    foreach ([
        $this->app->bootstrapPath('cache/capell-package-manifests.php'),
        $this->app->bootstrapPath('cache/capell-theme-chain.php'),
        $this->app->bootstrapPath('cache/capell-local-app-themes.php'),
    ] as $cachePath) {
        if (file_exists($cachePath)) {
            @unlink($cachePath);
        }
    }

    File::deleteDirectory($this->app->bootstrapPath('cache/capell-runtime'));

    expect(Artisan::call('capell:package-cache:clear'))->toBe(0)
        ->and(Artisan::output())->toContain('No Capell package cache files found.');
});

it('builds theme inheritance view chains and rejects invalid theme ancestry', function (): void {
    $rootPath = storage_path('framework/testing/theme-cache-chain-' . uniqid());
    $basePath = $rootPath . '/base-theme';
    $childPath = $rootPath . '/child-theme';

    File::ensureDirectoryExists($basePath . '/resources/views');
    File::ensureDirectoryExists($childPath . '/resources/views');

    $base = packageCacheThemeManifest('vendor/base-theme', $basePath, themeKey: 'base');
    $child = packageCacheThemeManifest('vendor/child-theme', $childPath, extends: 'vendor/base-theme', themeKey: 'child');
    $themeKeyChild = packageCacheThemeManifest('vendor/theme-key-child', $childPath, extends: 'base', themeKey: 'theme-key-child');
    $missingParent = packageCacheThemeManifest('vendor/missing-parent-theme', $childPath, extends: 'vendor/missing-theme');
    $cycleBase = packageCacheThemeManifest('vendor/cycle-base', $basePath, extends: 'vendor/cycle-child');
    $cycleChild = packageCacheThemeManifest('vendor/cycle-child', $childPath, extends: 'vendor/cycle-base');

    $registry = new CapellPackageRegistry;
    $registry->fill([
        $base->name => $base,
        $child->name => $child,
        $themeKeyChild->name => $themeKeyChild,
        $cycleBase->name => $cycleBase,
        $cycleChild->name => $cycleChild,
    ]);

    $walkChain = new ReflectionMethod(BuildPackageCacheAction::class, 'walkChain');
    $action = resolve(BuildPackageCacheAction::class);

    try {
        expect($walkChain->invoke($action, $child, $registry))->toBe([
            $childPath . '/resources/views',
            $basePath . '/resources/views',
        ]);

        expect($walkChain->invoke($action, $themeKeyChild, $registry))->toBe([
            $childPath . '/resources/views',
            $basePath . '/resources/views',
        ]);

        expect(fn (): array => $walkChain->invoke($action, $missingParent, $registry))
            ->toThrow(OutOfBoundsException::class, 'extends missing package [vendor/missing-theme]');

        expect(fn (): array => $walkChain->invoke($action, $cycleChild, $registry))
            ->toThrow(OutOfBoundsException::class, 'Theme inheritance cycle detected');
    } finally {
        File::deleteDirectory($rootPath);
    }
});

it('falls back to discovery when package cache returns invalid data', function (): void {
    $cachePath = $this->app->bootstrapPath('cache/capell-package-manifests.php');

    file_put_contents($cachePath, '<?php return "not an array";');

    expect(function (): void {
        resolve(PackageRegistryBootstrapper::class)->bootstrap();
    })->not->toThrow(Throwable::class)
        ->and(file_exists($cachePath))->toBeFalse();
});

it('falls back to discovery when package cache contains invalid php', function (): void {
    $cachePath = $this->app->bootstrapPath('cache/capell-package-manifests.php');

    file_put_contents($cachePath, '<?php this is not valid php');

    expect(function (): void {
        resolve(PackageRegistryBootstrapper::class)->bootstrap();
    })->not->toThrow(Throwable::class)
        ->and(file_exists($cachePath))->toBeFalse();
});

function packageCacheThemeManifest(string $name, string $installPath, ?string $extends = null, ?string $themeKey = null): CapellManifestData
{
    return CapellManifestData::fromArray(
        capellManifestV3Array(
            name: $name,
            surfaces: ['frontend'],
            overrides: array_filter([
                'kind' => 'theme',
                'extends' => $extends,
                'themeKey' => $themeKey,
            ], fn (mixed $value): bool => $value !== null),
        ),
        $installPath,
    );
}

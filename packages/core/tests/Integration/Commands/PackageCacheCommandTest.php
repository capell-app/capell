<?php

declare(strict_types=1);

use Capell\Core\Console\Commands\PackageCacheCommand;
use Capell\Core\Models\Theme;
use Capell\Core\Providers\CapellServiceProvider;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Capell\Frontend\Support\View\ThemeChainResolver;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

it('capell:package-cache writes package and theme chain cache files', function (): void {
    $packageCachePath = base_path('bootstrap/cache/capell-package-manifests.php');
    $themeCachePath = base_path('bootstrap/cache/capell-theme-chain.php');
    $localAppThemeCachePath = base_path('bootstrap/cache/capell-local-app-themes.php');

    foreach ([$packageCachePath, $themeCachePath, $localAppThemeCachePath] as $cachePath) {
        if (file_exists($cachePath)) {
            @unlink($cachePath);
        }
    }

    Artisan::call('capell:package-cache');

    expect(file_exists($packageCachePath))->toBeTrue()
        ->and(file_exists($themeCachePath))->toBeTrue()
        ->and(file_exists($localAppThemeCachePath))->toBeTrue();

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
})->afterEach(function (): void {
    foreach ([
        base_path('bootstrap/cache/capell-package-manifests.php'),
        base_path('bootstrap/cache/capell-theme-chain.php'),
        base_path('bootstrap/cache/capell-local-app-themes.php'),
    ] as $cachePath) {
        if (file_exists($cachePath)) {
            @unlink($cachePath);
        }
    }
});

it('CapellServiceProvider uses capell-package-manifests.php when present', function (): void {
    $cachePath = base_path('bootstrap/cache/capell-package-manifests.php');

    file_put_contents(
        $cachePath,
        '<?php return ' . var_export([
            'vendor/cached-package' => capellManifestV3Array(
                name: 'vendor/cached-package',
                surfaces: ['frontend'],
            ),
        ], return: true) . ';',
    );

    $provider = new CapellServiceProvider(app());
    $method = new ReflectionMethod($provider, 'bootCapellPackageRegistry');
    $method->invoke($provider);

    $registry = resolve(CapellPackageRegistry::class);

    @unlink($cachePath);

    expect($registry->has('vendor/cached-package'))->toBeTrue();
});

it('ThemeChainResolver uses capell-theme-chain.php when present', function (): void {
    $cachePath = base_path('bootstrap/cache/capell-theme-chain.php');

    file_put_contents($cachePath, '<?php return ["default" => ["/fake/views"]];');

    $registry = resolve(CapellPackageRegistry::class);
    $resolver = new ThemeChainResolver($registry, cachePath: $cachePath);

    $theme = Theme::factory()->make(['key' => 'default']);
    $paths = $resolver->resolve($theme);

    @unlink($cachePath);

    expect($paths)->toBe(['/fake/views']);
});

it('capell:package-cache:clear removes package and theme chain cache files', function (): void {
    $packageCachePath = base_path('bootstrap/cache/capell-package-manifests.php');
    $themeCachePath = base_path('bootstrap/cache/capell-theme-chain.php');
    $localAppThemeCachePath = base_path('bootstrap/cache/capell-local-app-themes.php');

    file_put_contents($packageCachePath, '<?php return [];');
    file_put_contents($themeCachePath, '<?php return [];');
    file_put_contents($localAppThemeCachePath, '<?php return [];');

    Artisan::call('capell:package-cache:clear');

    expect(file_exists($packageCachePath))->toBeFalse()
        ->and(file_exists($themeCachePath))->toBeFalse()
        ->and(file_exists($localAppThemeCachePath))->toBeFalse();
});

it('capell:package-cache:clear succeeds when cache files are already absent', function (): void {
    foreach ([
        base_path('bootstrap/cache/capell-package-manifests.php'),
        base_path('bootstrap/cache/capell-theme-chain.php'),
        base_path('bootstrap/cache/capell-local-app-themes.php'),
    ] as $cachePath) {
        if (file_exists($cachePath)) {
            @unlink($cachePath);
        }
    }

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

    $walkChain = new ReflectionMethod(PackageCacheCommand::class, 'walkChain');
    $command = new PackageCacheCommand;

    try {
        expect($walkChain->invoke($command, $child, $registry))->toBe([
            $childPath . '/resources/views',
            $basePath . '/resources/views',
        ]);

        expect($walkChain->invoke($command, $themeKeyChild, $registry))->toBe([
            $childPath . '/resources/views',
            $basePath . '/resources/views',
        ]);

        expect(fn (): array => $walkChain->invoke($command, $missingParent, $registry))
            ->toThrow(OutOfBoundsException::class, 'extends missing package [vendor/missing-theme]');

        expect(fn (): array => $walkChain->invoke($command, $cycleChild, $registry))
            ->toThrow(OutOfBoundsException::class, 'Theme inheritance cycle detected');
    } finally {
        File::deleteDirectory($rootPath);
    }
});

it('falls back to discovery when package cache returns invalid data', function (): void {
    $cachePath = base_path('bootstrap/cache/capell-package-manifests.php');

    file_put_contents($cachePath, '<?php return "not an array";');

    $provider = new CapellServiceProvider(app());
    $method = new ReflectionMethod($provider, 'bootCapellPackageRegistry');

    expect(fn (): mixed => $method->invoke($provider))->not->toThrow(Throwable::class)
        ->and(file_exists($cachePath))->toBeFalse();
});

it('falls back to discovery when package cache contains invalid php', function (): void {
    $cachePath = base_path('bootstrap/cache/capell-package-manifests.php');

    file_put_contents($cachePath, '<?php this is not valid php');

    $provider = new CapellServiceProvider(app());
    $method = new ReflectionMethod($provider, 'bootCapellPackageRegistry');

    expect(fn (): mixed => $method->invoke($provider))->not->toThrow(Throwable::class)
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

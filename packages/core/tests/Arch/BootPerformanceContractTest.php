<?php

declare(strict_types=1);

use Capell\Core\Console\Commands\PackageCacheCommand;
use Capell\Core\Console\Commands\PackageClearCacheCommand;
use Capell\Core\Support\Bootstrap\PackageRegistryBootstrapper;
use Capell\Core\Support\Manifest\ManifestLoader;
use Capell\Core\Support\Manifest\ManifestValidator;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;

it('keeps first-party wildcard model listeners to the documented bounded set', function (): void {
    // Only first-party source owns this contract. Walking the workspace root
    // also traverses vendor and accumulated Testbench runtimes under tests/.pest.
    $sourceRoot = dirname(__DIR__, 4) . '/packages';
    $listeners = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceRoot)) as $file) {
        if (! $file->isFile()) {
            continue;
        }

        if ($file->getExtension() !== 'php') {
            continue;
        }

        if (! str_contains((string) $file->getPathname(), '/src/')) {
            continue;
        }

        $contents = file_get_contents($file->getPathname());
        if (! is_string($contents)) {
            continue;
        }

        preg_match_all("/Event::listen\\('(eloquent\\.[^']*\\*)'/", $contents, $matches);
        $listeners = [...$listeners, ...$matches[1]];
    }

    expect($listeners)->toBe([
        'eloquent.created: *',
        'eloquent.updated: *',
        'eloquent.deleted: *',
    ]);
});

it('wires the package manifest cache into Laravel optimization without a clear hook', function (): void {
    // `optimize` must rebuild the cache, but `optimize:clear` must not delete
    // it: this cache gates HTTP boot, so clearing it takes the site down rather
    // than merely slowing it, unlike every other Laravel cache. Removal stays
    // available through the explicit command below.
    expect(ServiceProvider::$optimizeCommands['capell-package-manifests'] ?? null)
        ->toBe(PackageCacheCommand::class);

    expect(ServiceProvider::$optimizeClearCommands['capell-package-manifests'] ?? null)
        ->toBeNull();

    expect(class_exists(PackageClearCacheCommand::class))->toBeTrue();
});

it('prevents non-console package discovery in production when the manifest cache is absent', function (): void {
    $bootstrapper = bootstrapperForWebRequest(
        sys_get_temp_dir() . '/missing-capell-package-manifests.php',
        isProduction: true,
    );

    expect(fn () => $bootstrapper->bootstrap())
        ->toThrow(RuntimeException::class, 'Run [php artisan capell:package-cache] during deployment.');
});

it('rebuilds on demand for non-production web requests when the manifest cache is absent', function (): void {
    // Outside production the per-request discovery cost is irrelevant, and a
    // 500 on every page after a routine cache clear is not.
    $bootstrapper = bootstrapperForWebRequest(
        sys_get_temp_dir() . '/missing-capell-package-manifests.php',
        isProduction: false,
    );

    // Boot cannot complete against a mocked Application, so assert the thing
    // that matters: the deploy-gate failure is not what stops it.
    $thrown = null;

    try {
        $bootstrapper->bootstrap();
    } catch (Throwable $throwable) {
        $thrown = $throwable;
    }

    expect($thrown?->getMessage() ?? '')
        ->not->toContain('Run [php artisan capell:package-cache] during deployment.');
});

it('fails once when a production web request cannot remove an invalid manifest cache', function (): void {
    $cachePath = sys_get_temp_dir() . '/undeletable-capell-package-manifests-' . bin2hex(random_bytes(6));
    mkdir($cachePath);

    $bootstrapper = bootstrapperForWebRequest($cachePath, isProduction: true);

    try {
        expect(fn () => $bootstrapper->bootstrap())
            ->toThrow(RuntimeException::class, 'Run [php artisan capell:package-cache] during deployment.');
    } finally {
        rmdir($cachePath);
    }
});

it('never leaves a production web request unable to boot after capell:package-cache:clear', function (): void {
    // Regression for the 2026-09-15 incident: running `capell:package-cache:clear`
    // standalone against production left the manifest cache missing, and the next
    // web request 500'd (canDiscoverOnDemand() is false in production) until someone
    // manually reran the rebuild command. The command now rebuilds by default.
    //
    // The real capell:package-cache/capell:package-cache:clear commands write to
    // the app's bootstrap path, which defaults to the shared Testbench skeleton.
    // Redirect it to a throwaway directory so a killed run can't leak a stale
    // capell-runtime manifest set into unrelated tests (e.g. DoctorCommandTest).
    $originalBootstrapPath = app()->bootstrapPath();
    $isolatedBootstrapPath = storage_path('framework/testing/boot-performance-bootstrap-' . uniqid());
    File::ensureDirectoryExists($isolatedBootstrapPath . '/cache');
    app()->useBootstrapPath($isolatedBootstrapPath);

    try {
        Artisan::call('capell:package-cache');
        $cachePath = app()->bootstrapPath('cache/capell-package-manifests.php');

        expect(Artisan::call('capell:package-cache:clear'))->toBe(0)
            ->and(file_exists($cachePath))->toBeTrue();

        $bootstrapper = bootstrapperForWebRequest($cachePath, isProduction: true);

        expect(fn () => $bootstrapper->bootstrap())->not->toThrow(Throwable::class);
    } finally {
        app()->useBootstrapPath($originalBootstrapPath);
        File::deleteDirectory($isolatedBootstrapPath);
    }
});

function bootstrapperForWebRequest(string $cachePath, bool $isProduction): PackageRegistryBootstrapper
{
    $registry = new CapellPackageRegistry;
    $application = Mockery::mock(Application::class);
    $application->shouldReceive('make')->with(CapellPackageRegistry::class)->andReturn($registry);
    $application->shouldReceive('bootstrapPath')->with('cache/capell-package-manifests.php')->andReturn($cachePath);
    $application->shouldReceive('runningInConsole')->andReturnFalse();
    $application->shouldReceive('environment')->with('production')->andReturn($isProduction);

    return new PackageRegistryBootstrapper(
        $application,
        new ManifestLoader(new ManifestValidator),
    );
}

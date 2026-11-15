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
use Illuminate\Support\Facades\Date;
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

        $registry = new CapellPackageRegistry;
        $bootstrapper = bootstrapperForWebRequest($cachePath, isProduction: true, registry: $registry);

        $bootstrapper->bootstrap();

        expect($registry->all())->not->toBeEmpty();
    } finally {
        app()->useBootstrapPath($originalBootstrapPath);
        File::deleteDirectory($isolatedBootstrapPath);
    }
});

it('rediscovers packages outside production when Composer installed packages after the manifest cache was written', function (): void {
    $root = storage_path('framework/testing/stale-manifest-cache-' . bin2hex(random_bytes(6)));
    File::ensureDirectoryExists($root . '/vendor/composer');
    $cachePath = $root . '/capell-package-manifests.php';
    $installedPath = $root . '/vendor/composer/installed.json';
    $staleOnly = 'vendor/stale-cache-only';
    file_put_contents($cachePath, '<?php return ' . var_export([
        $staleOnly => capellManifestV3Array(name: $staleOnly),
    ], return: true) . ';');
    file_put_contents($installedPath, '{"packages":[]}');

    try {
        // Composer ran after the cache was written: the cached set is out of date.
        touch($cachePath, Date::now()->subMinutes(1)->getTimestamp());
        touch($installedPath, Date::now()->getTimestamp());
        $rediscovered = new CapellPackageRegistry;
        bootstrapperForWebRequest($cachePath, isProduction: false, registry: $rediscovered, basePath: $root)->bootstrap();

        expect($rediscovered->has($staleOnly))->toBeFalse()
            ->and($rediscovered->has('capell-app/core'))->toBeTrue();

        // The cache is newer than Composer's record: it is trusted as before.
        touch($installedPath, Date::now()->subMinutes(2)->getTimestamp());
        $cached = new CapellPackageRegistry;
        bootstrapperForWebRequest($cachePath, isProduction: false, registry: $cached, basePath: $root)->bootstrap();

        expect($cached->has($staleOnly))->toBeTrue();

        // Production keeps the deploy contract: the cache is used even when Composer is newer.
        touch($installedPath, Date::now()->getTimestamp());
        $production = new CapellPackageRegistry;
        bootstrapperForWebRequest($cachePath, isProduction: true, registry: $production, basePath: $root)->bootstrap();

        expect($production->has($staleOnly))->toBeTrue();

        // Production console boots (artisan, scheduler, queue workers) keep the cache too.
        $productionConsole = new CapellPackageRegistry;
        bootstrapperForWebRequest($cachePath, isProduction: true, registry: $productionConsole, basePath: $root, runningInConsole: true)->bootstrap();

        expect($productionConsole->has($staleOnly))->toBeTrue();
    } finally {
        File::deleteDirectory($root);
    }
});

function bootstrapperForWebRequest(string $cachePath, bool $isProduction, ?CapellPackageRegistry $registry = null, ?string $basePath = null, bool $runningInConsole = false): PackageRegistryBootstrapper
{
    $registry ??= new CapellPackageRegistry;
    $application = Mockery::mock(Application::class);
    // Everything but the registry resolves from the real container, so the loader's own
    // collaborators exist and a failure inside bootstrap() is a real failure.
    $application->shouldReceive('make')->andReturnUsing(
        fn (string $abstract): mixed => $abstract === CapellPackageRegistry::class ? $registry : app()->make($abstract),
    );
    $application->shouldReceive('register')->andReturnUsing(fn (mixed $provider): ServiceProvider => app()->register($provider));
    $application->shouldReceive('bootstrapPath')->with('cache/capell-package-manifests.php')->andReturn($cachePath);
    $application->shouldReceive('runningInConsole')->andReturn($runningInConsole);
    $application->shouldReceive('environment')->with('production')->andReturn($isProduction);
    $application->shouldReceive('basePath')->with('vendor/composer/installed.json')->andReturn(($basePath ?? base_path()) . '/vendor/composer/installed.json');

    return new PackageRegistryBootstrapper(
        $application,
        new ManifestLoader(new ManifestValidator),
    );
}

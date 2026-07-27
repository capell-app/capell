<?php

declare(strict_types=1);

use Capell\Core\Console\Commands\PackageCacheCommand;
use Capell\Core\Console\Commands\PackageClearCacheCommand;
use Capell\Core\Support\Bootstrap\PackageRegistryBootstrapper;
use Capell\Core\Support\Manifest\ManifestLoader;
use Capell\Core\Support\Manifest\ManifestValidator;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

it('keeps first-party wildcard model listeners to the documented bounded set', function (): void {
    $sourceRoot = dirname(__DIR__, 4);
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

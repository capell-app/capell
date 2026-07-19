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
        if (! $file->isFile() || $file->getExtension() !== 'php' || ! str_contains($file->getPathname(), '/src/')) {
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

it('wires the package manifest cache into Laravel optimization', function (): void {
    expect(ServiceProvider::$optimizeCommands['capell-package-manifests'] ?? null)
        ->toBe(PackageCacheCommand::class)
        ->and(ServiceProvider::$optimizeClearCommands['capell-package-manifests'] ?? null)
        ->toBe(PackageClearCacheCommand::class);
});

it('prevents non-console package discovery when the manifest cache is absent', function (): void {
    $registry = new CapellPackageRegistry;
    $application = Mockery::mock(Application::class);
    $application->shouldReceive('make')->once()->with(CapellPackageRegistry::class)->andReturn($registry);
    $application->shouldReceive('bootstrapPath')->once()->with('cache/capell-package-manifests.php')->andReturn(
        sys_get_temp_dir() . '/missing-capell-package-manifests.php',
    );
    $application->shouldReceive('runningInConsole')->once()->andReturnFalse();

    $bootstrapper = new PackageRegistryBootstrapper(
        $application,
        new ManifestLoader(new ManifestValidator),
    );

    expect(fn () => $bootstrapper->bootstrap())
        ->toThrow(RuntimeException::class, 'Run [php artisan capell:package-cache] during deployment.');
});

it('fails once when a non-console invalid manifest cache cannot be removed', function (): void {
    $cachePath = sys_get_temp_dir() . '/undeletable-capell-package-manifests-' . bin2hex(random_bytes(6));
    mkdir($cachePath);

    $registry = new CapellPackageRegistry;
    $application = Mockery::mock(Application::class);
    $application->shouldReceive('make')->once()->with(CapellPackageRegistry::class)->andReturn($registry);
    $application->shouldReceive('bootstrapPath')->once()->with('cache/capell-package-manifests.php')->andReturn($cachePath);
    $application->shouldReceive('runningInConsole')->once()->andReturnFalse();

    $bootstrapper = new PackageRegistryBootstrapper(
        $application,
        new ManifestLoader(new ManifestValidator),
    );

    try {
        expect(fn () => $bootstrapper->bootstrap())
            ->toThrow(RuntimeException::class, 'Run [php artisan capell:package-cache] during deployment.');
    } finally {
        rmdir($cachePath);
    }
});

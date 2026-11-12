<?php

declare(strict_types=1);

use Capell\Core\Actions\EnablePackageAction;
use Capell\Core\Actions\Install\RunDeclaredPackageMigrationsAction;
use Capell\Core\Actions\InstallPackageAction;
use Capell\Core\Actions\RuntimeRefresh\PreparePackageRuntimeReloadAction;
use Capell\Core\Actions\RuntimeRefresh\RefreshInstalledPackageRuntimeAction;
use Capell\Core\Events\PackageInstalled;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Components\ComponentRegistry;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    CapellCore::registerPackage('test/component-cache-activation');
    CapellCore::forcePackageInstalled('test/component-cache-activation', false);
    Cache::forget('illuminate:queue:restart');
});

it('refuses activation when persisted component cache removal fails', function (string $operation, string $cache, bool $reportedSuccess): void {
    $package = CapellCore::getPackage('test/component-cache-activation');
    if ($operation === 'refresh') {
        CapellCore::markPackageInstalled($package->name);
    } elseif ($operation === 'enable') {
        CapellCore::markPackageDisabled($package->name);
    }

    $files = new Filesystem;
    $directory = base_path('bootstrap/cache/filament');
    $path = $cache === 'filament' ? $directory . '/panels/activation.php' : resolve(ComponentRegistry::class)->getComponentCachePath();
    $files->ensureDirectoryExists(dirname($path));
    $files->put($path, '<?php return [];');

    $failingFiles = Mockery::mock(Filesystem::class)->makePartial();
    $failingFiles->shouldReceive($cache === 'filament' ? 'deleteDirectory' : 'delete')
        ->with($cache === 'filament' ? $directory : $path)->andReturn($reportedSuccess);
    app()->instance(Filesystem::class, $failingFiles);

    try {
        expect(fn () => match ($operation) {
            'install' => InstallPackageAction::run($package),
            'enable' => EnablePackageAction::run($package),
            'refresh' => RefreshInstalledPackageRuntimeAction::run($package),
            default => throw new LogicException('Unknown runtime operation.'),
        })->toThrow(RuntimeException::class, __('capell-core::runtime-refresh.cache_refresh_required'));

        expect($files->exists($path))->toBeTrue()
            ->and(Cache::get('illuminate:queue:restart'))->toBeNull();
    } finally {
        app()->instance(Filesystem::class, $files);
        $files->delete($path);
        if ($cache === 'filament') {
            $files->deleteDirectory($directory);
        }
    }
})->with(['install', 'enable', 'refresh'])->with(['filament', 'capell'])->with([false, true]);

it('completes activation when persisted component caches are absent', function (string $operation): void {
    $package = CapellCore::getPackage('test/component-cache-activation');
    if ($operation === 'refresh') {
        CapellCore::markPackageInstalled($package->name);
    } elseif ($operation === 'enable') {
        CapellCore::markPackageDisabled($package->name);
    }

    $files = Mockery::mock(Filesystem::class)->makePartial();
    expect($files->exists(resolve(ComponentRegistry::class)->getComponentCachePath()))->toBeFalse()
        ->and($files->exists(base_path('bootstrap/cache/filament')))->toBeFalse();
    $files->shouldReceive('delete')->with(resolve(ComponentRegistry::class)->getComponentCachePath())->andReturnFalse();
    $files->shouldReceive('deleteDirectory')->with(base_path('bootstrap/cache/filament'))->andReturnFalse();
    app()->instance(Filesystem::class, $files);

    match ($operation) {
        'install' => InstallPackageAction::run($package),
        'enable' => EnablePackageAction::run($package),
        'refresh' => RefreshInstalledPackageRuntimeAction::run($package),
        default => throw new LogicException('Unknown runtime operation.'),
    };

    expect(resolve(ComponentRegistry::class)->hasCachedComponents())->toBeFalse();
})->with(['install', 'enable', 'refresh']);

it('finishes a failed activation on retry without repeating the install lifecycle', function (): void {
    Event::fake([PackageInstalled::class]);
    $package = CapellCore::getPackage('test/component-cache-activation');
    $cachePath = resolve(ComponentRegistry::class)->getComponentCachePath();
    $activation = new stdClass;
    $activation->fail = true;
    // Fail only the activation refresh that follows the install lifecycle, not
    // the runtime reload inside it, by finding a component cache that cannot be
    // removed at that point.
    $failing = function () use ($activation): bool {
        if ($activation->fail !== true) {
            return false;
        }

        $frames = collect(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS));

        return $frames->contains(fn (array $frame): bool => ($frame['class'] ?? null) === InstallPackageAction::class)
            && $frames->doesntContain(fn (array $frame): bool => ($frame['class'] ?? null) === PreparePackageRuntimeReloadAction::class);
    };
    $realFiles = new Filesystem;
    $files = Mockery::mock(Filesystem::class)->makePartial();
    $files->shouldReceive('exists')->andReturnUsing(fn (string $path): bool => ($failing() && $path === $cachePath) || $realFiles->exists($path));
    $files->shouldReceive('delete')->andReturnUsing(fn (mixed $paths): bool => ! $failing() && $realFiles->delete($paths));
    app()->instance(Filesystem::class, $files);
    $lifecycleRuns = 0;
    RunDeclaredPackageMigrationsAction::mock()->shouldReceive('handle')->andReturnUsing(function () use (&$lifecycleRuns): void {
        $lifecycleRuns++;
    });

    expect(fn () => InstallPackageAction::run($package))
        ->toThrow(RuntimeException::class, __('capell-core::runtime-refresh.cache_refresh_required'));

    expect(CapellCore::isPackageInstalled($package->name))->toBeTrue()
        ->and($lifecycleRuns)->toBe(1);
    Event::assertNotDispatched(PackageInstalled::class);

    $activation->fail = false;
    InstallPackageAction::run($package);

    expect($lifecycleRuns)->toBe(1)
        ->and(CapellCore::isPackageInstalled($package->name))->toBeTrue();
    Event::assertDispatchedTimes(PackageInstalled::class, 1);
});

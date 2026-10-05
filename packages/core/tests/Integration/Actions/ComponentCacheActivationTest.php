<?php

declare(strict_types=1);

use Capell\Core\Actions\EnablePackageAction;
use Capell\Core\Actions\InstallPackageAction;
use Capell\Core\Actions\RuntimeRefresh\RefreshInstalledPackageRuntimeAction;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Components\ComponentRegistry;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;

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
        ->once()->with($cache === 'filament' ? $directory : $path)->andReturn($reportedSuccess);
    app()->instance(Filesystem::class, $failingFiles);

    try {
        expect(fn () => match ($operation) {
            'install' => InstallPackageAction::run($package),
            'enable' => EnablePackageAction::run($package),
            'refresh' => RefreshInstalledPackageRuntimeAction::run($package),
            default => throw new LogicException('Unknown runtime operation.'),
        })->toThrow(RuntimeException::class, __('capell::runtime-refresh.cache_refresh_required'));

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

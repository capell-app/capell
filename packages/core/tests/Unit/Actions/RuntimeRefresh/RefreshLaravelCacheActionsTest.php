<?php

declare(strict_types=1);

use Capell\Core\Actions\RuntimeRefresh\RefreshConfigurationCacheAction;
use Capell\Core\Actions\RuntimeRefresh\RefreshRouteCacheAction;
use Capell\Core\Actions\RuntimeRefresh\RunArtisanRuntimeRefreshStageAction;
use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Console\ClosureCommand;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

it('preserves uncached modes and rebuilds active Laravel caches', function (bool $cached): void {
    $directory = sys_get_temp_dir() . '/capell-refresh-' . bin2hex(random_bytes(8));
    File::ensureDirectoryExists($directory . '/bootstrap/cache');
    $application = new Application($directory);
    // Constructing an Application replaces the global container; keep the test application's services active.
    Container::setInstance($this->app);
    $application->instance('files', new Filesystem);
    $paths = ['config:cache' => $application->getCachedConfigPath(), 'route:cache' => $application->getCachedRoutesPath()];
    if ($cached) {
        foreach ($paths as $path) {
            File::put($path, 'old cache');
        }
    }

    Artisan::all();
    foreach ($paths as $name => $path) {
        Artisan::registerCommand(new ClosureCommand($name, function () use ($path): int {
            File::put($path, 'rebuilt cache');

            return 0;
        }));
    }

    $stage = new RunArtisanRuntimeRefreshStageAction(resolve(Kernel::class));

    try {
        $config = new RefreshConfigurationCacheAction($application, $stage)->handle();
        $routes = new RefreshRouteCacheAction($application, $stage)->handle();
        expect($config->skipped)->toBe(! $cached)
            ->and($config->passed)->toBeTrue()
            ->and($routes->skipped)->toBe(! $cached)
            ->and($routes->passed)->toBeTrue();
        foreach ($paths as $path) {
            expect(File::exists($path))->toBe($cached);
            if ($cached) {
                expect(File::get($path))->toBe('rebuilt cache');
            }
        }
    } finally {
        File::deleteDirectory($directory);
    }
})->with([false, true]);

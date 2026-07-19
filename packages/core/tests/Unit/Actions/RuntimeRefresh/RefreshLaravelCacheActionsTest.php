<?php

declare(strict_types=1);

use Capell\Core\Actions\RuntimeRefresh\RefreshConfigurationCacheAction;
use Capell\Core\Actions\RuntimeRefresh\RefreshRouteCacheAction;
use Capell\Core\Actions\RuntimeRefresh\RunArtisanRuntimeRefreshStageAction;
use Capell\Core\Data\RuntimeRefresh\RuntimeRefreshStageResultData;
use Illuminate\Foundation\Application;

it('preserves uncached configuration and route modes', function (): void {
    $application = Mockery::mock(Application::class);
    $application->shouldReceive('configurationIsCached')->once()->andReturnFalse();
    $application->shouldReceive('routesAreCached')->once()->andReturnFalse();
    $artisan = Mockery::mock(RunArtisanRuntimeRefreshStageAction::class);
    $artisan->shouldNotReceive('handle');

    $config = (new RefreshConfigurationCacheAction($application, $artisan))->handle();
    $routes = (new RefreshRouteCacheAction($application, $artisan))->handle();

    expect($config->skipped)->toBeTrue()
        ->and($config->passed)->toBeTrue()
        ->and($routes->skipped)->toBeTrue()
        ->and($routes->passed)->toBeTrue();
});

it('rebuilds configuration and routes only when their caches are active', function (): void {
    $application = Mockery::mock(Application::class);
    $application->shouldReceive('configurationIsCached')->once()->andReturnTrue();
    $application->shouldReceive('routesAreCached')->once()->andReturnTrue();
    $artisan = Mockery::mock(RunArtisanRuntimeRefreshStageAction::class);
    $artisan->shouldReceive('handle')
        ->once()
        ->with('config', 'Laravel configuration cache', 'config:cache')
        ->andReturn(new RuntimeRefreshStageResultData('config', 'Laravel configuration cache', true, 'rebuilt'));
    $artisan->shouldReceive('handle')
        ->once()
        ->with('routes', 'Laravel route cache', 'route:cache')
        ->andReturn(new RuntimeRefreshStageResultData('routes', 'Laravel route cache', true, 'rebuilt'));

    $config = (new RefreshConfigurationCacheAction($application, $artisan))->handle();
    $routes = (new RefreshRouteCacheAction($application, $artisan))->handle();

    expect($config->skipped)->toBeFalse()
        ->and($config->passed)->toBeTrue()
        ->and($routes->skipped)->toBeFalse()
        ->and($routes->passed)->toBeTrue();
});

<?php

declare(strict_types=1);

use Capell\Core\Actions\RuntimeRefresh\RefreshConfigurationCacheAction;
use Capell\Core\Actions\RuntimeRefresh\RefreshRouteCacheAction;
use Capell\Core\Actions\RuntimeRefresh\RunArtisanRuntimeRefreshStageAction;
use Capell\Core\Actions\RuntimeRefresh\RunRuntimeDoctorAction;
use Capell\Core\Actions\RuntimeRefresh\RunRuntimeRefreshAction;
use Capell\Core\Actions\RuntimeRefresh\WarmRuntimeAction;
use Capell\Core\Data\RuntimeRefresh\RuntimeRefreshStageResultData;

function runtimeRefreshStage(string $key, bool $passed = true): RuntimeRefreshStageResultData
{
    return new RuntimeRefreshStageResultData(
        key: $key,
        label: ucfirst($key),
        passed: $passed,
        message: $passed ? 'passed' : 'failed',
    );
}

it('runs runtime refresh stages in their safe deployment order', function (): void {
    $order = [];
    $artisan = Mockery::mock(RunArtisanRuntimeRefreshStageAction::class);
    $config = Mockery::mock(RefreshConfigurationCacheAction::class);
    $routes = Mockery::mock(RefreshRouteCacheAction::class);
    $warm = Mockery::mock(WarmRuntimeAction::class);
    $doctor = Mockery::mock(RunRuntimeDoctorAction::class);

    $artisan->shouldReceive('handle')->once()->with('packages', 'Capell package cache', 'capell:package-cache')->ordered()
        ->andReturnUsing(function () use (&$order): RuntimeRefreshStageResultData {
            $order[] = 'packages';

            return runtimeRefreshStage('packages');
        });
    $artisan->shouldReceive('handle')->once()->with('views', 'Compiled views', 'view:clear')->ordered()
        ->andReturnUsing(function () use (&$order): RuntimeRefreshStageResultData {
            $order[] = 'views';

            return runtimeRefreshStage('views');
        });
    $config->shouldReceive('handle')->once()->ordered()->andReturnUsing(function () use (&$order): RuntimeRefreshStageResultData {
        $order[] = 'config';

        return runtimeRefreshStage('config');
    });
    $routes->shouldReceive('handle')->once()->ordered()->andReturnUsing(function () use (&$order): RuntimeRefreshStageResultData {
        $order[] = 'routes';

        return runtimeRefreshStage('routes');
    });
    $warm->shouldReceive('handle')->once()->ordered()->andReturnUsing(function () use (&$order): RuntimeRefreshStageResultData {
        $order[] = 'warm';

        return runtimeRefreshStage('warm');
    });
    $doctor->shouldReceive('handle')->once()->ordered()->andReturnUsing(function () use (&$order): RuntimeRefreshStageResultData {
        $order[] = 'doctor';

        return runtimeRefreshStage('doctor');
    });

    $result = (new RunRuntimeRefreshAction($artisan, $config, $routes, $warm, $doctor))->handle();

    expect($order)->toBe(['packages', 'views', 'config', 'routes', 'warm', 'doctor'])
        ->and($result->passed())->toBeTrue();
});

it('continues independent stages and aggregates a partial failure', function (): void {
    $artisan = Mockery::mock(RunArtisanRuntimeRefreshStageAction::class);
    $config = Mockery::mock(RefreshConfigurationCacheAction::class);
    $routes = Mockery::mock(RefreshRouteCacheAction::class);
    $warm = Mockery::mock(WarmRuntimeAction::class);
    $doctor = Mockery::mock(RunRuntimeDoctorAction::class);

    $artisan->shouldReceive('handle')->with('packages', 'Capell package cache', 'capell:package-cache')->once()
        ->andReturn(runtimeRefreshStage('packages', false));
    $artisan->shouldReceive('handle')->with('views', 'Compiled views', 'view:clear')->once()
        ->andReturn(runtimeRefreshStage('views'));
    $config->shouldReceive('handle')->once()->andThrow(new RuntimeException('config cache failed'));
    $routes->shouldReceive('handle')->once()->andReturn(runtimeRefreshStage('routes'));
    $warm->shouldReceive('handle')->once()->andReturn(runtimeRefreshStage('warm'));
    $doctor->shouldReceive('handle')->once()->andReturn(runtimeRefreshStage('doctor'));

    $result = (new RunRuntimeRefreshAction($artisan, $config, $routes, $warm, $doctor))->handle();

    expect($result->passed())->toBeFalse()
        ->and($result->stages)->toHaveCount(6)
        ->and($result->stages->pluck('key')->all())->toBe(['packages', 'views', 'config', 'routes', 'warm', 'doctor'])
        ->and($result->stages->firstWhere('key', 'config')?->message)->toBe('config cache failed')
        ->and($result->stages->where('passed', true))->toHaveCount(4);
});

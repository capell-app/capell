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

function runtimeRefreshFixture(bool $fail = false): RunRuntimeRefreshAction
{
    $artisan = new class($fail) extends RunArtisanRuntimeRefreshStageAction
    {
        public function __construct(private readonly bool $fail) {}

        #[Override]
        public function handle(string $key, string $label, string $command): RuntimeRefreshStageResultData
        {
            return runtimeRefreshStage($key, ! $this->fail || $key !== 'packages');
        }
    };
    $config = new class($fail) extends RefreshConfigurationCacheAction
    {
        public function __construct(private readonly bool $fail) {}

        #[Override]
        public function handle(bool $rebuild = true): RuntimeRefreshStageResultData
        {
            throw_if($this->fail, RuntimeException::class, 'config cache failed');

            return runtimeRefreshStage('config');
        }
    };
    $routes = new class extends RefreshRouteCacheAction
    {
        public function __construct() {}

        #[Override]
        public function handle(bool $rebuild = true): RuntimeRefreshStageResultData
        {
            return runtimeRefreshStage('routes');
        }
    };
    $warm = new class extends WarmRuntimeAction
    {
        public function __construct() {}

        #[Override]
        public function handle(): RuntimeRefreshStageResultData
        {
            return runtimeRefreshStage('warm');
        }
    };
    $doctor = new class extends RunRuntimeDoctorAction
    {
        #[Override]
        public function handle(): RuntimeRefreshStageResultData
        {
            return runtimeRefreshStage('doctor');
        }
    };

    return new RunRuntimeRefreshAction($artisan, $config, $routes, $warm, $doctor);
}

it('returns a successful report for every runtime refresh stage', function (): void {
    $result = runtimeRefreshFixture()->handle();
    expect($result->passed())->toBeTrue()
        ->and($result->stages->pluck('key')->all())->toEqualCanonicalizing(['packages', 'views', 'config', 'routes', 'warm', 'doctor', 'workers']);
    foreach ($result->stages as $stage) {
        expect($stage->passed)->toBeTrue()->and($stage->message)->toBe('passed');
    }
});

it('reports failures while retaining results from independent stages', function (): void {
    $result = runtimeRefreshFixture(fail: true)->handle();
    $stages = $result->stages->keyBy('key');
    expect($result->passed())->toBeFalse()
        ->and($stages->keys()->all())->toEqualCanonicalizing(['packages', 'views', 'config', 'routes', 'warm', 'doctor', 'workers'])
        ->and($stages->get('packages')?->passed)->toBeFalse()
        ->and($stages->get('config')?->passed)->toBeFalse()
        ->and($stages->get('config')?->message)->toBe('config cache failed')
        ->and($result->stages->where('passed', true)->pluck('key')->all())->toEqualCanonicalizing(['views', 'routes', 'warm', 'doctor', 'workers']);
});

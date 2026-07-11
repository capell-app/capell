<?php

declare(strict_types=1);

use Capell\Core\Actions\Upgrade\RunSettingsMigrationsAction;
use Illuminate\Contracts\Console\Kernel;

it('invokes settings:migrate --force', function (): void {
    $calls = [];
    $kernel = Mockery::mock(Kernel::class);
    $kernel->shouldReceive('all')->andReturn(['settings:migrate' => ['description' => 'Run settings migrations']]);
    $kernel->shouldReceive('call')->once()->andReturnUsing(function (string $command, array $parameters = []) use (&$calls): int {
        $calls[] = [$command, $parameters];

        return 0;
    });
    $kernel->shouldReceive('output')->andReturn('Settings migrated');
    $this->app->instance(Kernel::class, $kernel);

    $result = RunSettingsMigrationsAction::run();

    expect($result->exitCode)->toBe(0)
        ->and($calls)->toBe([['settings:migrate', ['--force' => true]]]);
});

it('dry-run skips artisan', function (): void {
    $kernel = Mockery::mock(Kernel::class);
    $kernel->shouldNotReceive('call');

    $this->app->instance(Kernel::class, $kernel);

    $result = RunSettingsMigrationsAction::run(dryRun: true);

    expect($result->output)->toContain('[dry-run]');
});

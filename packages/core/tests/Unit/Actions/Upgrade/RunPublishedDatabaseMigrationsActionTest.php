<?php

declare(strict_types=1);

use Capell\Core\Actions\Upgrade\RunPublishedDatabaseMigrationsAction;
use Illuminate\Contracts\Console\Kernel;

it('runs migrations published into the host application', function (): void {
    $calls = [];
    $kernel = Mockery::mock(Kernel::class);
    $kernel->shouldReceive('call')->once()->andReturnUsing(function (string $command, array $parameters = []) use (&$calls): int {
        $calls[] = [$command, $parameters];

        return 0;
    });
    $kernel->shouldReceive('output')->andReturn('Published migrations ran');
    $this->app->instance(Kernel::class, $kernel);

    $result = RunPublishedDatabaseMigrationsAction::run();

    expect($result->exitCode)->toBe(0)
        ->and($result->output)->toContain('Published migrations ran')
        ->and($calls)->toBe([['migrate', [
            '--force' => true,
            '--path' => database_path('migrations'),
            '--realpath' => true,
        ]]]);
});

it('does not invoke artisan during a dry run', function (): void {
    $kernel = Mockery::mock(Kernel::class);
    $kernel->shouldNotReceive('call');

    $this->app->instance(Kernel::class, $kernel);

    $result = RunPublishedDatabaseMigrationsAction::run(dryRun: true);

    expect($result->exitCode)->toBe(0)
        ->and($result->output)->toContain('[dry-run]');
});

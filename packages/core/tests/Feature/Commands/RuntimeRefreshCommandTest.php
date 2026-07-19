<?php

declare(strict_types=1);

use Capell\Core\Actions\RuntimeRefresh\RunRuntimeRefreshAction;
use Capell\Core\Data\RuntimeRefresh\RuntimeRefreshResultData;
use Capell\Core\Data\RuntimeRefresh\RuntimeRefreshStageResultData;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Command\Command;

it('reports successful and skipped runtime refresh stages', function (): void {
    $action = Mockery::mock(RunRuntimeRefreshAction::class);
    $action->shouldReceive('handle')->once()->andReturn(new RuntimeRefreshResultData(collect([
        new RuntimeRefreshStageResultData('packages', 'Capell package cache', true, 'rebuilt'),
        new RuntimeRefreshStageResultData('config', 'Laravel configuration cache', true, 'uncached mode preserved', true),
    ])));
    app()->instance(RunRuntimeRefreshAction::class, $action);

    $exitCode = Artisan::call('capell:runtime-refresh');
    $output = Artisan::output();

    expect($exitCode)->toBe(Command::SUCCESS)
        ->and($output)->toContain('Capell package cache [passed]')
        ->and($output)->toContain('Laravel configuration cache [skipped]')
        ->and($output)->toContain('completed successfully');
});

it('returns a non-zero exit code and retains all stage failures', function (): void {
    $action = Mockery::mock(RunRuntimeRefreshAction::class);
    $action->shouldReceive('handle')->once()->andReturn(new RuntimeRefreshResultData(collect([
        new RuntimeRefreshStageResultData('packages', 'Capell package cache', false, 'manifest invalid'),
        new RuntimeRefreshStageResultData('doctor', 'Capell Doctor', false, 'homepage failed'),
    ])));
    app()->instance(RunRuntimeRefreshAction::class, $action);

    $exitCode = Artisan::call('capell:runtime-refresh');
    $output = Artisan::output();

    expect($exitCode)->toBe(Command::FAILURE)
        ->and($output)->toContain('Capell package cache [failed]')
        ->and($output)->toContain('manifest invalid')
        ->and($output)->toContain('Capell Doctor [failed]')
        ->and($output)->toContain('homepage failed')
        ->and($output)->toContain('completed with failures');
});

<?php

declare(strict_types=1);

use Capell\Core\Actions\Upgrade\CreateUpgradeRunAction;
use Capell\Core\Data\Upgrade\UpgradeReadinessReportData;
use Capell\Core\Data\UpgradeRunOptions;
use Capell\Core\Enums\Upgrade\UpgradeReadinessResult;
use Capell\Core\Enums\Upgrade\UpgradeRunEventLevel;
use Capell\Core\Enums\Upgrade\UpgradeRunStatus;
use Capell\Core\Enums\Upgrade\UpgradeStage;
use Capell\Core\Support\Upgrade\DatabaseUpgradeReporter;

it('records stage events and redacted output excerpts', function (): void {
    $run = CreateUpgradeRunAction::run(
        options: new UpgradeRunOptions,
        readiness: new UpgradeReadinessReportData(UpgradeReadinessResult::Ready, []),
        status: UpgradeRunStatus::Running,
        manualCommands: [],
    );

    $reporter = new DatabaseUpgradeReporter($run);
    $reporter->stage(UpgradeStage::Migrations, 'Migration phase started.');
    $reporter->error('Failed with password=super-secret');

    $events = $run->events()->latest('id')->take(2)->get();

    expect($run->refresh()->current_stage)->toBe(UpgradeStage::Migrations)
        ->and($events->first()?->level)->toBe(UpgradeRunEventLevel::Error)
        ->and($events->first()?->message)->toBe('Failed with password= [redacted]')
        ->and($events->first()?->output_excerpt)->toContain('password= [redacted]');
});

it('persists normal informational lines as database events', function (): void {
    $run = CreateUpgradeRunAction::run(
        options: new UpgradeRunOptions,
        readiness: new UpgradeReadinessReportData(UpgradeReadinessResult::Ready, []),
        status: UpgradeRunStatus::Running,
        manualCommands: [],
    );

    $initialEventCount = $run->events()->count();
    $reporter = new DatabaseUpgradeReporter($run);

    $reporter->line('Inspecting package capell-app/example');
    $reporter->info('Still working');

    expect($run->events()->count())->toBe($initialEventCount + 2)
        ->and($run->events()->latest('id')->first()?->message)->toBe('Still working');
});

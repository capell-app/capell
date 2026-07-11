<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Unit\Actions\Upgrade;

use Capell\Core\Actions\Upgrade\BuildUpgradePlanAction;
use Capell\Core\Actions\Upgrade\RollbackUpgradeStepAction;
use Capell\Core\Actions\Upgrade\RunUpgradeStepAction;
use Capell\Core\Data\UpgradeContext;
use Capell\Core\Models\UpgradeLogEntry;
use Capell\Core\Tests\Unit\Actions\Upgrade\Fixtures\IrreversibleStep;
use Capell\Core\Tests\Unit\Actions\Upgrade\Fixtures\ReversibleStep;

function rollbackCtx(): UpgradeContext
{
    return new UpgradeContext([], [], [], false, 'rollback');
}

beforeEach(function (): void {
    ReversibleStep::$rollbacks = 0;
    app()->tag([ReversibleStep::class, IrreversibleStep::class], 'capell.upgrade-steps');
});

it('rolls back a successful step and supersedes the original success row', function (): void {
    $step = new ReversibleStep;
    RunUpgradeStepAction::run($step, rollbackCtx());

    $result = RollbackUpgradeStepAction::run($step, rollbackCtx());

    expect($result->status)->toBe('rolled_back')
        ->and(ReversibleStep::$rollbacks)->toBe(1)
        ->and(UpgradeLogEntry::query()->steps()->where('key', $step->id())->where('status', 'rolled_back')->exists())->toBeTrue()
        ->and(UpgradeLogEntry::query()->steps()->where('key', $step->id())->where('status', 'superseded')->exists())->toBeTrue()
        ->and(UpgradeLogEntry::query()->steps()->where('key', $step->id())->where('status', 'success')->exists())->toBeFalse();
});

it('re-running a rolled-back step applies it again', function (): void {
    $step = new ReversibleStep;
    RunUpgradeStepAction::run($step, rollbackCtx());
    RollbackUpgradeStepAction::run($step, rollbackCtx());

    $applied = BuildUpgradePlanAction::run()->context->appliedStepIds;
    expect($applied)->not->toContain($step->id());
});

it('refuses to roll back an irreversible step', function (): void {
    RunUpgradeStepAction::run(new IrreversibleStep, rollbackCtx());

    $result = RollbackUpgradeStepAction::run(new IrreversibleStep, rollbackCtx());

    expect($result->status)->toBe('failed')
        ->and($result->output)->toContain('not reversible');
});

it('refuses to roll back a step that has not run', function (): void {
    $result = RollbackUpgradeStepAction::run(new ReversibleStep, rollbackCtx());

    expect($result->status)->toBe('skipped')
        ->and($result->output)->toContain('no successful run');
});

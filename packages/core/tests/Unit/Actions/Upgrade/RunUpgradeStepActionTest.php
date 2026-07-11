<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Unit\Actions\Upgrade;

use Capell\Core\Actions\Upgrade\RunUpgradeStepAction;
use Capell\Core\Data\UpgradeContext;
use Capell\Core\Data\UpgradeStepResult;
use Capell\Core\Models\UpgradeLogEntry;
use Capell\Core\Support\Upgrade\AbstractUpgradeStep;
use Capell\Core\Tests\Unit\Actions\Upgrade\Fixtures\ReturningFalseStep;
use Capell\Core\Tests\Unit\Actions\Upgrade\Fixtures\ThrowingStep;
use Capell\Core\Tests\Unit\Actions\Upgrade\Fixtures\TrackedStep;
use Capell\Core\Tests\Unit\Actions\Upgrade\Fixtures\VersionGatedStep;
use RuntimeException;

function freshContext(array $composer = [], bool $dryRun = false): UpgradeContext
{
    return new UpgradeContext(
        composerVersions: $composer,
        ledgerVersions: [],
        appliedStepIds: [],
        dryRun: $dryRun,
    );
}

it('records success on first run and skips the second', function (): void {
    $step = new TrackedStep;

    $first = RunUpgradeStepAction::run($step, freshContext());
    $second = RunUpgradeStepAction::run($step, freshContext());

    expect($first)->toBeInstanceOf(UpgradeStepResult::class)
        ->and($first->status)->toBe('success')
        ->and($second->status)->toBe('skipped')
        ->and($step->runCount)->toBe(1)
        ->and(UpgradeLogEntry::query()->steps()->where('key', 'core.test.tracked')->where('status', 'success')->count())->toBe(1);
});

it('captures exception message and records failure', function (): void {
    $result = RunUpgradeStepAction::run(new ThrowingStep, freshContext());

    expect($result->status)->toBe('failed')
        ->and($result->output)->toContain('kaboom')
        ->and(UpgradeLogEntry::query()->steps()->where('key', 'core.test.throwing')->where('status', 'failed')->exists())->toBeTrue();
});

it('redacts failed step output before persisting upgrade log metadata', function (): void {
    $result = RunUpgradeStepAction::run(new class extends ThrowingStep
    {
        public function run(UpgradeContext $context): bool
        {
            throw new RuntimeException('Failed with password=hunter2 and Bearer abc123');
        }
    }, freshContext());

    $entry = expectPresent(UpgradeLogEntry::query()
        ->steps()
        ->where('key', 'core.test.throwing')
        ->where('status', 'failed')
        ->first());

    expect($result->output)->toBe('Failed with password=hunter2 and Bearer abc123')
        ->and($entry->metaGet('output'))->toBe('Failed with password= [redacted] and Bearer [redacted]');
});

it('records failure without a success row when run returns false', function (): void {
    $result = RunUpgradeStepAction::run(new ReturningFalseStep, freshContext());

    expect($result->status)->toBe('failed')
        ->and($result->output)->toContain('run() returned false')
        ->and(UpgradeLogEntry::query()->steps()->where('key', 'core.test.returning-false')->where('status', 'failed')->exists())->toBeTrue()
        ->and(UpgradeLogEntry::query()->steps()->where('key', 'core.test.returning-false')->where('status', 'success')->exists())->toBeFalse();
});

it('allows retry after failure', function (): void {
    $step = new TrackedStep;
    UpgradeLogEntry::query()->create([
        'type' => 'step',
        'key' => $step->id(),
        'package' => $step->package(),
        'status' => 'failed',
        'ran_at' => now(),
    ]);

    $result = RunUpgradeStepAction::run($step, freshContext());

    expect($result->status)->toBe('success')
        ->and($step->runCount)->toBe(1);
});

it('respects shouldRun() via context', function (): void {
    $step = new VersionGatedStep;

    $whenOld = RunUpgradeStepAction::run($step, freshContext(['capell-app/capell' => '4.4.0']));
    UpgradeLogEntry::query()->delete();
    $whenNew = RunUpgradeStepAction::run($step, freshContext(['capell-app/capell' => '5.1.0']));

    expect($whenOld->status)->toBe('success')
        ->and($whenNew->status)->toBe('skipped');
});

it('does not execute or persist when context is dry-run', function (): void {
    $step = new TrackedStep;

    $result = RunUpgradeStepAction::run($step, freshContext(dryRun: true));

    expect($result->status)->toBe('dry-run')
        ->and($step->runCount)->toBe(0)
        ->and(UpgradeLogEntry::query()->count())->toBe(0);
});

it('allows re-run with force', function (): void {
    $step = new TrackedStep;
    RunUpgradeStepAction::run($step, freshContext());
    $second = RunUpgradeStepAction::run($step, freshContext(), force: true);

    expect($second->status)->toBe('success')
        ->and($step->runCount)->toBe(2)
        ->and(UpgradeLogEntry::query()->steps()->where('key', $step->id())->where('status', 'success')->count())->toBe(2);
});

it('skips when dependencies have not run', function (): void {
    $step = new class extends AbstractUpgradeStep
    {
        public function id(): string
        {
            return 'core.test.dependent';
        }

        public function label(): string
        {
            return 'Dependent';
        }

        public function dependsOn(): array
        {
            return ['core.test.missing-dep'];
        }

        public function run(UpgradeContext $context): bool
        {
            return true;
        }
    };

    $result = RunUpgradeStepAction::run($step, freshContext());

    expect($result->status)->toBe('skipped')
        ->and($result->output)->toContain('Missing dependency');
});

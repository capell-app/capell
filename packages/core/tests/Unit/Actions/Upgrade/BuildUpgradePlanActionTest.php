<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Unit\Actions\Upgrade;

use Capell\Core\Actions\Upgrade\BuildUpgradePlanAction;
use Capell\Core\Data\UpgradePlanData;
use Capell\Core\Models\UpgradeLogEntry;
use Capell\Core\Tests\Unit\Actions\Upgrade\Fixtures\AlreadyAppliedStep;
use Capell\Core\Tests\Unit\Actions\Upgrade\Fixtures\DependencyBaseStep;
use Capell\Core\Tests\Unit\Actions\Upgrade\Fixtures\DependentEarlyPriorityStep;
use Capell\Core\Tests\Unit\Actions\Upgrade\Fixtures\PendingHighPriorityStep;
use Capell\Core\Tests\Unit\Actions\Upgrade\Fixtures\PendingLowPriorityStep;
use Capell\Core\Tests\Unit\Actions\Upgrade\Fixtures\ShouldNotRunStep;

it('returns pending steps sorted by priority, excluding applied and shouldRun=false', function (): void {
    app()->tag(
        [PendingHighPriorityStep::class, PendingLowPriorityStep::class, AlreadyAppliedStep::class, ShouldNotRunStep::class],
        'capell.upgrade-steps',
    );

    UpgradeLogEntry::query()->create([
        'type' => 'step', 'key' => 'core.already-done', 'package' => 'capell-app/capell',
        'status' => 'success', 'ran_at' => now(),
    ]);
    UpgradeLogEntry::query()->create([
        'type' => 'version_snapshot', 'key' => 'capell-app/capell', 'package' => 'capell-app/capell',
        'status' => 'recorded', 'ran_at' => now()->subDay(),
        'meta' => ['to_version' => '4.4.0'],
    ]);

    $plan = BuildUpgradePlanAction::run();

    expect($plan)->toBeInstanceOf(UpgradePlanData::class)
        ->and(array_map(fn ($step) => $step->id(), $plan->pendingSteps))->toBe(['core.pending-high', 'core.pending-low'])
        ->and($plan->context->ledgerVersion('capell-app/capell'))->toBe('4.4.0')
        ->and($plan->context->appliedStepIds)->toContain('core.already-done');
});

it('orders pending steps after their dependencies before applying priority', function (): void {
    app()->tag(
        [DependentEarlyPriorityStep::class, DependencyBaseStep::class],
        'capell.upgrade-steps',
    );

    $plan = BuildUpgradePlanAction::run();

    expect(array_map(fn ($step) => $step->id(), $plan->pendingSteps))
        ->toBe(['core.dependency-base', 'core.dependent-early']);
});

it('uses the latest version snapshot by ran_at and id', function (): void {
    UpgradeLogEntry::query()->create([
        'type' => 'version_snapshot', 'key' => 'capell-app/capell', 'package' => 'capell-app/capell',
        'status' => 'recorded', 'ran_at' => now()->subDay(),
        'meta' => ['to_version' => '4.4.0'],
    ]);
    UpgradeLogEntry::query()->create([
        'type' => 'version_snapshot', 'key' => 'capell-app/capell', 'package' => 'capell-app/capell',
        'status' => 'recorded', 'ran_at' => now(),
        'meta' => ['to_version' => '4.5.0'],
    ]);
    UpgradeLogEntry::query()->create([
        'type' => 'version_snapshot', 'key' => 'capell-app/capell', 'package' => 'capell-app/capell',
        'status' => 'recorded', 'ran_at' => now(),
        'meta' => ['to_version' => '4.6.0'],
    ]);

    $plan = BuildUpgradePlanAction::run();

    expect($plan->context->ledgerVersion('capell-app/capell'))->toBe('4.6.0');
});

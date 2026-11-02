<?php

declare(strict_types=1);

use Capell\Core\Actions\Security\ApplyApprovedSecurityRemediationsAction;
use Capell\Core\Actions\Security\BuildSecurityPostureInventoryAction;
use Capell\Core\Actions\Security\RunSecurityWeaknessScanAction;
use Capell\Core\Data\Security\SecurityPostureInventoryData;
use Capell\Core\Enums\Security\SecurityFindingStatus;
use Capell\Core\Events\SecurityRemediationApplied;
use Capell\Core\Events\SecurityWeaknessDetected;
use Capell\Core\Models\SecurityRemediationApproval;
use Capell\Core\Models\SecurityRemediationRun;
use Capell\Core\Models\SecurityWeaknessFinding;
use Capell\Core\Support\Security\Checks\DebugEnabledCheck;
use Capell\Core\Support\Security\NullSecurityWeaknessAnalyser;
use Capell\Core\Support\Security\SecurityWeaknessRegistry;
use Capell\Core\Tests\Support\Security\TestRemediation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;

it('opens, refreshes, resolves and reopens the same finding', function (): void {
    Event::fake([SecurityWeaknessDetected::class]);
    $registry = new SecurityWeaknessRegistry;
    $registry->registerCheck(new DebugEnabledCheck);

    $on = new SecurityPostureInventoryData(null, [], ['app.debug' => true], [], CarbonImmutable::now());
    $off = new SecurityPostureInventoryData(null, [], ['app.debug' => false], [], CarbonImmutable::now());
    BuildSecurityPostureInventoryAction::mock()->shouldReceive('handle')->andReturn($on, $on, $off, $on);
    $action = new RunSecurityWeaknessScanAction($registry, new NullSecurityWeaknessAnalyser);
    expect($action->handle(false)->newFindings)->toBe(1)
        ->and($action->handle(false)->newFindings)->toBe(0)
        ->and($action->handle(false)->resolvedFindings)->toBe(1)
        ->and($action->handle(false)->reopenedFindings)->toBe(1);
    expect(SecurityWeaknessFinding::query()->count())->toBe(1);
    Event::assertDispatchedTimes(SecurityWeaknessDetected::class, 2);
});

it('requires active approval and records an approved change but not a dry run', function (): void {
    Event::fake([SecurityRemediationApplied::class]);
    $registry = new SecurityWeaknessRegistry;
    $registry->registerRemediation(new TestRemediation);

    $finding = SecurityWeaknessFinding::factory()->create();
    $action = new ApplyApprovedSecurityRemediationsAction($registry);
    expect($action->handle('test')->applied)->toBe(0);
    $approval = SecurityRemediationApproval::factory()->create(['weakness_id' => $finding->weakness_id, 'revoked_at' => now()]);
    expect($action->handle('test')->applied)->toBe(0);
    $approval->update(['revoked_at' => null]);
    expect($action->handle('test', true)->skipped[$finding->fingerprint])->toBe('dry-run')
        ->and(SecurityRemediationRun::query()->count())->toBe(0)
        ->and($action->handle('test')->applied)->toBe(1)
        ->and($finding->fresh()->status)->toBe(SecurityFindingStatus::Remediated)
        ->and(SecurityRemediationRun::query()->count())->toBe(1);
    Event::assertDispatched(SecurityRemediationApplied::class);
});

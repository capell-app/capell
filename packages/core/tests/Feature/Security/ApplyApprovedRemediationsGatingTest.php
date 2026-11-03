<?php

declare(strict_types=1);

use Capell\Core\Actions\Security\ApplyApprovedSecurityRemediationsAction;
use Capell\Core\Actions\Security\ApproveSecurityRemediationAction;
use Capell\Core\Actions\Security\BuildSecurityPostureInventoryAction;
use Capell\Core\Actions\Security\RunSecurityWeaknessScanAction;
use Capell\Core\Contracts\Security\SecurityWeaknessCheck;
use Capell\Core\Contracts\Security\SecurityWeaknessRemediation;
use Capell\Core\Data\Security\SecurityPostureInventoryData;
use Capell\Core\Data\Security\SecurityRemediationOutcomeData;
use Capell\Core\Data\Security\SecurityWeaknessFindingData;
use Capell\Core\Enums\Security\SecurityFindingSource;
use Capell\Core\Enums\Security\SecurityFindingStatus;
use Capell\Core\Enums\Security\SecurityRemediationOutcome;
use Capell\Core\Enums\Security\SecurityWeaknessSeverity;
use Capell\Core\Events\SecurityRemediationApplied;
use Capell\Core\Models\SecurityRemediationApproval;
use Capell\Core\Models\SecurityRemediationRun;
use Capell\Core\Models\SecurityWeaknessFinding;
use Capell\Core\Support\Security\NullSecurityWeaknessAnalyser;
use Capell\Core\Support\Security\SecurityWeaknessRegistry;
use Capell\Core\Tests\Support\Security\TestRemediation;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Event;

it('gates built-in findings by registry and active approval and applies each once', function (): void {
    Event::fake([SecurityRemediationApplied::class]);
    $registry = new SecurityWeaknessRegistry;
    $registry->registerRemediation(new TestRemediation);

    $unknown = SecurityWeaknessFinding::factory()->create(['weakness_id' => 'unknown.issue', 'source' => SecurityFindingSource::Analyser]);
    $known = SecurityWeaknessFinding::factory()->create(['source' => SecurityFindingSource::Builtin]);
    SecurityRemediationApproval::factory()->create(['weakness_id' => 'unknown.issue']);
    $approval = SecurityRemediationApproval::factory()->create(['revoked_at' => now(), 'remediation_class' => TestRemediation::class]);
    $action = new ApplyApprovedSecurityRemediationsAction($registry);
    $revoked = $action->handle('test');
    expect($revoked->skipped[$unknown->fingerprint])->toBe(__('capell-core::security.remediation.advice_only'))
        ->and($revoked->skipped[$known->fingerprint])->toBe('approval required');
    $approval->update(['revoked_at' => null]);
    $dry = $action->handle('test', true);
    expect($dry->skipped[$known->fingerprint])->toBe('dry-run')
        ->and(SecurityRemediationRun::query()->count())->toBe(0)
        ->and($known->fresh()->status)->toBe(SecurityFindingStatus::Open);
    expect($action->handle('test')->applied)->toBe(1)
        ->and($action->handle('test')->applied)->toBe(0)
        ->and(SecurityRemediationRun::query()->count())->toBe(1)
        ->and($known->fresh()->status)->toBe(SecurityFindingStatus::Remediated);
    Event::assertDispatchedTimes(SecurityRemediationApplied::class, 1);
});

it('records a safe failure and leaves the finding open', function (): void {
    Event::fake([SecurityRemediationApplied::class]);
    $remediation = Mockery::mock(SecurityWeaknessRemediation::class);
    $remediation->shouldReceive('weaknessId')->andReturn('test.failure');
    $remediation->shouldReceive('apply')->once()->andThrow(new RuntimeException('secret exception detail'));
    $registry = new SecurityWeaknessRegistry;
    $registry->registerRemediation($remediation);

    $finding = SecurityWeaknessFinding::factory()->create(['weakness_id' => 'test.failure']);
    SecurityRemediationApproval::factory()->create(['weakness_id' => 'test.failure', 'remediation_class' => $remediation::class]);
    $result = new ApplyApprovedSecurityRemediationsAction($registry)->handle('test');
    $run = SecurityRemediationRun::query()->sole();
    expect($result->failed)->toBe(1)->and($run->outcome)->toBe(SecurityRemediationOutcome::Failed)
        ->and($run->message)->toBe(__('capell-core::security.remediation.failed'))
        ->and($finding->fresh()->status)->toBe(SecurityFindingStatus::Open);
    Event::assertNotDispatched(SecurityRemediationApplied::class);
});

it('limits a batch to the requested weakness id', function (): void {
    $registry = new SecurityWeaknessRegistry;
    $registry->registerRemediation(new TestRemediation);

    $finding = SecurityWeaknessFinding::factory()->create();
    $other = SecurityWeaknessFinding::factory()->create(['weakness_id' => 'other.issue']);
    SecurityRemediationApproval::factory()->create(['remediation_class' => TestRemediation::class]);
    $result = new ApplyApprovedSecurityRemediationsAction($registry)->handle('test', false, 'app.debug_enabled');
    expect($result->considered)->toBe(1)->and($result->applied)->toBe(1)
        ->and($finding->fresh()->status)->toBe(SecurityFindingStatus::Remediated)
        ->and($other->fresh()->status)->toBe(SecurityFindingStatus::Open);
});

it('never applies analyser advice even when the same weakness has an approved built-in finding', function (): void {
    $registry = new SecurityWeaknessRegistry;
    $registry->registerRemediation(new TestRemediation);

    $builtin = SecurityWeaknessFinding::factory()->create(['source' => SecurityFindingSource::Builtin]);
    $advice = SecurityWeaknessFinding::factory()->create(['source' => SecurityFindingSource::Analyser]);
    SecurityRemediationApproval::factory()->create(['remediation_class' => TestRemediation::class]);
    $result = new ApplyApprovedSecurityRemediationsAction($registry)->handle('test');
    expect($result->applied)->toBe(1)->and($builtin->fresh()->status)->toBe(SecurityFindingStatus::Remediated)
        ->and($advice->fresh()->status)->toBe(SecurityFindingStatus::Open)
        ->and($result->skipped[$advice->fingerprint])->toBe(__('capell-core::security.remediation.advice_only'));
});

it('skips stale findings, failed checks and changed remediation classes', function (): void {
    $registry = new SecurityWeaknessRegistry;
    $registry->registerRemediation(new TestRemediation);

    $finding = SecurityWeaknessFinding::factory()->create(['last_detected_at' => now()->subDay()]);
    $approval = SecurityRemediationApproval::factory()->create(['remediation_class' => TestRemediation::class]);
    $action = new ApplyApprovedSecurityRemediationsAction($registry);
    expect($action->handle('test', detectedSince: CarbonImmutable::now())->skipped[$finding->fingerprint])->toBe(__('capell-core::security.remediation.not_confirmed'))
        ->and($action->handle('test', failedChecks: ['app.debug_enabled'])->skipped[$finding->fingerprint])->toBe(__('capell-core::security.remediation.not_confirmed'));
    $approval->update(['remediation_class' => 'ChangedFix']);
    expect($action->handle('test')->skipped[$finding->fingerprint])->toBe(__('capell-core::security.remediation.fix_changed'))
        ->and(SecurityRemediationRun::query()->count())->toBe(0);
});

it('redacts remediation output before recording and dispatching it', function (): void {
    Event::fake([SecurityRemediationApplied::class]);
    $remediation = Mockery::mock(SecurityWeaknessRemediation::class);
    $remediation->shouldReceive('weaknessId')->andReturn('test.secret');
    $remediation->shouldReceive('apply')->once()->andReturn(new SecurityRemediationOutcomeData(true, 'Bearer distinctiveToken123', ['detail' => 'APP_KEY=base64:distinctiveSecret123'], ['detail' => 'Bearer distinctiveToken123']));
    $registry = new SecurityWeaknessRegistry;
    $registry->registerRemediation($remediation);
    SecurityWeaknessFinding::factory()->create(['weakness_id' => 'test.secret']);
    SecurityRemediationApproval::factory()->create(['weakness_id' => 'test.secret', 'remediation_class' => $remediation::class]);
    new ApplyApprovedSecurityRemediationsAction($registry)->handle('test');
    $run = SecurityRemediationRun::query()->sole();
    expect(json_encode($run->toArray()))->not->toContain('distinctiveToken123')->not->toContain('distinctiveSecret123');
    Event::assertDispatched(SecurityRemediationApplied::class, fn ($event): bool => ! str_contains(json_encode($event->run->toArray()), 'distinctiveToken123'));
});

it('supersedes approval when the same finding has changed evidence and accepts reapproval', function (): void {
    $evidence = ['value' => 'first'];
    $check = Mockery::mock(SecurityWeaknessCheck::class);
    $check->shouldReceive('weaknessId')->andReturn('app.debug_enabled');
    $check->shouldReceive('detect')->andReturnUsing(function () use (&$evidence): array {
        return [new SecurityWeaknessFindingData('app.debug_enabled', 'same-fingerprint', SecurityWeaknessSeverity::High, SecurityFindingSource::Builtin, 'Title', 'Summary', $evidence, remediable: true)];
    });
    BuildSecurityPostureInventoryAction::mock()->shouldReceive('handle')->andReturn(new SecurityPostureInventoryData(null, [], [], [], CarbonImmutable::now()));
    $registry = new SecurityWeaknessRegistry;
    $registry->registerCheck($check)->registerRemediation(new TestRemediation);
    $scanner = new RunSecurityWeaknessScanAction($registry, new NullSecurityWeaknessAnalyser);
    $scanner->handle(false);

    $user = Mockery::mock(Authenticatable::class);
    $user->shouldReceive('getAuthIdentifier')->andReturn(1);
    $approve = new ApproveSecurityRemediationAction($registry);
    $approval = $approve->handle('app.debug_enabled', $user);
    $apply = new ApplyApprovedSecurityRemediationsAction($registry);
    $scanner->handle(false);
    expect($apply->handle('test', true)->skipped[SecurityWeaknessFinding::query()->sole()->fingerprint])->toBe('dry-run');
    expect($apply->handle('test')->applied)->toBe(1);

    $scanner->handle(false);
    $evidence = ['value' => 'second'];
    $scanner->handle(false);
    expect($apply->handle('test')->applied)->toBe(0)
        ->and($approval->refresh()->superseded_at)->not->toBeNull()
        ->and(SecurityRemediationRun::query()->count())->toBe(1);
    $approve->handle('app.debug_enabled', $user);
    expect($apply->handle('test')->applied)->toBe(1)
        ->and($approval->refresh()->superseded_at)->toBeNull();
});

it('retains legacy approvals but never applies them without renewed consent', function (): void {
    $registry = new SecurityWeaknessRegistry;
    $registry->registerRemediation(new TestRemediation);

    $finding = SecurityWeaknessFinding::factory()->create();
    $approval = SecurityRemediationApproval::factory()->create(['remediation_class' => TestRemediation::class]);
    $approval->update(['approved_for_scan_id' => null, 'approved_fingerprint' => null, 'evidence_hash' => null]);
    expect(new ApplyApprovedSecurityRemediationsAction($registry)->handle('test')->applied)->toBe(0)
        ->and($approval->fresh())->not->toBeNull()
        ->and($finding->fresh()->status)->toBe(SecurityFindingStatus::Open);
});

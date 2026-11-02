<?php

declare(strict_types=1);

use Capell\Core\Actions\Security\ApplyApprovedSecurityRemediationsAction;
use Capell\Core\Contracts\Security\SecurityWeaknessRemediation;
use Capell\Core\Enums\Security\SecurityFindingSource;
use Capell\Core\Enums\Security\SecurityFindingStatus;
use Capell\Core\Enums\Security\SecurityRemediationOutcome;
use Capell\Core\Events\SecurityRemediationApplied;
use Capell\Core\Models\SecurityRemediationApproval;
use Capell\Core\Models\SecurityRemediationRun;
use Capell\Core\Models\SecurityWeaknessFinding;
use Capell\Core\Support\Security\SecurityWeaknessRegistry;
use Capell\Core\Tests\Support\Security\TestRemediation;
use Illuminate\Support\Facades\Event;

it('gates analyser findings by registry and active approval and applies each once', function (): void {
    Event::fake([SecurityRemediationApplied::class]);
    $registry = new SecurityWeaknessRegistry;
    $registry->registerRemediation(new TestRemediation);

    $unknown = SecurityWeaknessFinding::factory()->create(['weakness_id' => 'unknown.issue', 'source' => SecurityFindingSource::Analyser]);
    $known = SecurityWeaknessFinding::factory()->create(['source' => SecurityFindingSource::Analyser]);
    SecurityRemediationApproval::factory()->create(['weakness_id' => 'unknown.issue']);
    $approval = SecurityRemediationApproval::factory()->create(['revoked_at' => now()]);
    $action = new ApplyApprovedSecurityRemediationsAction($registry);
    $revoked = $action->handle('test');
    expect($revoked->skipped[$unknown->fingerprint])->toBe('not eligible')
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
    SecurityRemediationApproval::factory()->create(['weakness_id' => 'test.failure']);
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
    SecurityRemediationApproval::factory()->create();
    $result = new ApplyApprovedSecurityRemediationsAction($registry)->handle('test', false, 'app.debug_enabled');
    expect($result->considered)->toBe(1)->and($result->applied)->toBe(1)
        ->and($finding->fresh()->status)->toBe(SecurityFindingStatus::Remediated)
        ->and($other->fresh()->status)->toBe(SecurityFindingStatus::Open);
});

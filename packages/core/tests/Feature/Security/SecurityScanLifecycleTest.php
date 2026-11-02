<?php

declare(strict_types=1);

use Capell\Core\Actions\Security\BuildSecurityPostureInventoryAction;
use Capell\Core\Actions\Security\RunSecurityWeaknessScanAction;
use Capell\Core\Contracts\Security\SecurityWeaknessAnalyser;
use Capell\Core\Contracts\Security\SecurityWeaknessCheck;
use Capell\Core\Data\Security\SecurityPostureInventoryData;
use Capell\Core\Data\Security\SecurityWeaknessFindingData;
use Capell\Core\Enums\Security\SecurityFindingSource;
use Capell\Core\Enums\Security\SecurityFindingStatus;
use Capell\Core\Enums\Security\SecurityWeaknessSeverity;
use Capell\Core\Events\SecurityWeaknessDetected;
use Capell\Core\Models\SecurityWeaknessFinding;
use Capell\Core\Support\Diagnostics\Checks\SecurityPostureCheck;
use Capell\Core\Support\Security\Checks\DebugEnabledCheck;
use Capell\Core\Support\Security\SecurityWeaknessRegistry;
use Capell\Core\Tests\Support\Security\TestRemediation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;

it('keeps a dismissed finding dismissed and isolates a failing check', function (): void {
    Event::fake([SecurityWeaknessDetected::class]);
    $inventory = new SecurityPostureInventoryData(null, [], ['app.debug' => true], [], CarbonImmutable::now());
    BuildSecurityPostureInventoryAction::mock()->shouldReceive('handle')->andReturn($inventory);
    $broken = Mockery::mock(SecurityWeaknessCheck::class);
    $broken->shouldReceive('weaknessId')->andReturn('test.broken');
    $broken->shouldReceive('detect')->andThrow(new RuntimeException('broken'));
    $analyser = Mockery::mock(SecurityWeaknessAnalyser::class);
    $analyser->shouldNotReceive('isAvailable');

    $registry = new SecurityWeaknessRegistry;
    $registry->registerCheck($broken)->registerCheck(new DebugEnabledCheck);
    $action = new RunSecurityWeaknessScanAction($registry, $analyser);
    $first = $action->handle(false);
    $finding = SecurityWeaknessFinding::query()->sole();
    $finding->update(['status' => SecurityFindingStatus::Dismissed]);

    $second = $action->handle(false);
    expect($first->failures)->toContain('check: test.broken')
        ->and($first->newFindings)->toBe(1)
        ->and($second->newFindings)->toBe(0)
        ->and($finding->refresh()->status)->toBe(SecurityFindingStatus::Dismissed);
    Event::assertDispatchedTimes(SecurityWeaknessDetected::class, 1);
});

it('normalises analyser findings as advice only', function (): void {
    $inventory = new SecurityPostureInventoryData(null, [], [], [], CarbonImmutable::now());
    BuildSecurityPostureInventoryAction::mock()->shouldReceive('handle')->andReturn($inventory);
    $analyser = Mockery::mock(SecurityWeaknessAnalyser::class);
    $analyser->shouldReceive('isAvailable')->once()->andReturnTrue();
    $analyser->shouldReceive('name')->once()->andReturn('test analyser');
    $analyser->shouldReceive('analyse')->once()->andReturn([
        new SecurityWeaknessFindingData('unknown.issue', 'unknown', SecurityWeaknessSeverity::High, SecurityFindingSource::Builtin, 'Unknown', 'Summary', remediable: true),
        new SecurityWeaknessFindingData('app.debug_enabled', 'known', SecurityWeaknessSeverity::High, SecurityFindingSource::Builtin, 'Known', 'Summary', remediable: true),
    ]);
    $registry = new SecurityWeaknessRegistry;
    $registry->registerRemediation(new TestRemediation);

    $action = new RunSecurityWeaknessScanAction($registry, $analyser);
    expect($action->handle(true)->analyserUsed)->toBe('test analyser')
        ->and($action->handle(false)->analyserUsed)->toBeNull();
    $unknown = SecurityWeaknessFinding::query()->where('fingerprint', 'unknown')->sole();
    $known = SecurityWeaknessFinding::query()->where('fingerprint', 'known')->sole();
    expect($unknown->source)->toBe(SecurityFindingSource::Analyser)->and($unknown->remediable)->toBeFalse()
        ->and($known->source)->toBe(SecurityFindingSource::Analyser)->and($known->remediable)->toBeFalse();
});

it('reopens a remediated finding that is still detected', function (): void {
    Event::fake([SecurityWeaknessDetected::class]);
    BuildSecurityPostureInventoryAction::mock()->shouldReceive('handle')->andReturn(new SecurityPostureInventoryData(null, [], ['app.debug' => true, 'app.env' => 'production'], [], CarbonImmutable::now()));
    $analyser = Mockery::mock(SecurityWeaknessAnalyser::class);
    $registry = new SecurityWeaknessRegistry;
    $registry->registerCheck(new DebugEnabledCheck);

    $action = new RunSecurityWeaknessScanAction($registry, $analyser);
    $action->handle(false);

    $finding = SecurityWeaknessFinding::query()->sole();
    $finding->update(['status' => SecurityFindingStatus::Remediated]);
    expect($action->handle(false)->reopenedFindings)->toBe(1)
        ->and($finding->refresh()->status)->toBe(SecurityFindingStatus::Open)
        ->and((new SecurityPostureCheck)->check()->passed)->toBeFalse();
    Event::assertDispatchedTimes(SecurityWeaknessDetected::class, 2);
});

it('redacts analyser secrets before storing and dispatching findings', function (): void {
    Event::fake([SecurityWeaknessDetected::class]);
    BuildSecurityPostureInventoryAction::mock()->shouldReceive('handle')->andReturn(new SecurityPostureInventoryData(null, [], [], [], CarbonImmutable::now()));
    $analyser = Mockery::mock(SecurityWeaknessAnalyser::class);
    $analyser->shouldReceive('isAvailable')->andReturnTrue();
    $analyser->shouldReceive('name')->andReturn('test');
    $analyser->shouldReceive('analyse')->andReturn([new SecurityWeaknessFindingData('test.issue', 'secret-test', SecurityWeaknessSeverity::High, SecurityFindingSource::Analyser, 'Bearer distinctiveToken123', 'APP_KEY=base64:distinctiveSecret123', ['detail' => 'Bearer distinctiveToken123'])]);
    new RunSecurityWeaknessScanAction(new SecurityWeaknessRegistry, $analyser)->handle();
    $finding = SecurityWeaknessFinding::query()->sole();
    expect(json_encode($finding->toArray()))->not->toContain('distinctiveToken123')->not->toContain('distinctiveSecret123');
    Event::assertDispatched(SecurityWeaknessDetected::class, fn ($event): bool => ! str_contains(json_encode($event->finding->toArray()), 'distinctiveToken123'));
});

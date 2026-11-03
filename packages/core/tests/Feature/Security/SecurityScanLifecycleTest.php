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
use Capell\Core\Models\SecurityScanRun;
use Capell\Core\Models\SecurityWeaknessFinding;
use Capell\Core\Support\Diagnostics\Checks\SecurityPostureCheck;
use Capell\Core\Support\Security\Checks\DebugEnabledCheck;
use Capell\Core\Support\Security\NullSecurityWeaknessAnalyser;
use Capell\Core\Support\Security\SecurityWeaknessRegistry;
use Capell\Core\Tests\Support\Security\TestRemediation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
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
    $unknown = SecurityWeaknessFinding::query()->where('fingerprint', hash('sha256', 'unknown'))->sole();
    $known = SecurityWeaknessFinding::query()->where('fingerprint', hash('sha256', 'known'))->sole();
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

it('rejects invalid identifiers and keeps secrets out of stored findings events and command output', function (): void {
    Event::fake([SecurityWeaknessDetected::class]);
    BuildSecurityPostureInventoryAction::mock()->shouldReceive('handle')->andReturn(new SecurityPostureInventoryData(null, [], [], [], CarbonImmutable::now()));
    $analyser = Mockery::mock(SecurityWeaknessAnalyser::class);
    $analyser->shouldReceive('isAvailable')->andReturnTrue();
    $analyser->shouldReceive('name')->andReturn('test');
    $analyser->shouldReceive('analyse')->andReturn([
        new SecurityWeaknessFindingData('SECRET-ID!', 'safe', SecurityWeaknessSeverity::High, SecurityFindingSource::Analyser, 'Invalid', 'Summary'),
        new SecurityWeaknessFindingData('test.valid', 'SECRET-FINGERPRINT!', SecurityWeaknessSeverity::High, SecurityFindingSource::Analyser, 'Valid', 'Summary', composerName: 'SECRET-COMPOSER!'),
    ]);
    $check = Mockery::mock(SecurityWeaknessCheck::class);
    $check->shouldReceive('weaknessId')->andReturn('test.check');
    $check->shouldReceive('detect')->andReturn([
        new SecurityWeaknessFindingData('SECRET-ID!', 'check', SecurityWeaknessSeverity::High, SecurityFindingSource::Builtin, 'Invalid', 'Summary'),
        new SecurityWeaknessFindingData('test.check', 'check-fingerprint', SecurityWeaknessSeverity::High, SecurityFindingSource::Builtin, 'Check', 'Summary', composerName: 'SECRET-COMPOSER!'),
    ]);
    $registry = new SecurityWeaknessRegistry;
    $registry->registerCheck($check);

    app()->instance(SecurityWeaknessRegistry::class, $registry);
    app()->instance(SecurityWeaknessAnalyser::class, $analyser);

    $scan = new RunSecurityWeaknessScanAction($registry, $analyser)->handle();
    expect($scan->failures)->toContain('analyser: invalid finding')->toContain('check: test.check: invalid finding')
        ->and(SecurityWeaknessFinding::query()->count())->toBe(2)
        ->and(SecurityWeaknessFinding::query()->where('weakness_id', 'test.valid')->sole()->fingerprint)->toBe(hash('sha256', 'SECRET-FINGERPRINT!'))
        ->and(SecurityWeaknessFinding::query()->where('weakness_id', 'test.check')->sole()->composer_name)->toBeNull();
    $outputs = [];
    foreach ([false, true] as $json) {
        expect(Artisan::call('capell:security:scan', ['--json' => $json]))->toBe(2);
        $outputs[] = Artisan::output();
    }

    $tables = [];
    foreach (['capell_security_weakness_findings', 'capell_security_remediation_approvals', 'capell_security_remediation_runs'] as $table) {
        $tables[] = DB::table($table)->get()->toArray();
    }

    $payload = json_encode([$tables, $outputs, Event::dispatched(SecurityWeaknessDetected::class)->map(fn (array $arguments): array => $arguments[0]->finding->toArray())->all()], JSON_THROW_ON_ERROR);
    expect($payload)->toContain('test.valid')->toContain('test.check')
        ->not->toContain('SECRET-ID!')->not->toContain('SECRET-FINGERPRINT!')->not->toContain('SECRET-COMPOSER!');
});

it('records a distinct scan identity on each run and on detected findings', function (): void {
    $inventory = new SecurityPostureInventoryData(null, [], ['app.debug' => true], [], CarbonImmutable::now());
    BuildSecurityPostureInventoryAction::mock()->shouldReceive('handle')->andReturn($inventory);
    $registry = new SecurityWeaknessRegistry;
    $registry->registerCheck(new DebugEnabledCheck);

    $action = new RunSecurityWeaknessScanAction($registry, new NullSecurityWeaknessAnalyser);
    $first = $action->handle(false);
    $second = $action->handle(false);
    $finding = SecurityWeaknessFinding::query()->sole();
    expect($first->scanId)->not->toBeNull()->not->toBe($second->scanId)
        ->and($finding->first_detected_scan_id)->toBe($first->scanId)
        ->and($finding->last_detected_scan_id)->toBe($second->scanId)
        ->and(SecurityScanRun::query()->whereNotNull('finished_at')->count())->toBe(2);
});

it('rejects a finding attributed to a different detector and records the failure', function (): void {
    BuildSecurityPostureInventoryAction::mock()->shouldReceive('handle')->andReturn(new SecurityPostureInventoryData(null, [], [], [], CarbonImmutable::now()));
    $check = Mockery::mock(SecurityWeaknessCheck::class);
    $check->shouldReceive('weaknessId')->andReturn('test.registered');
    $check->shouldReceive('detect')->andReturn([new SecurityWeaknessFindingData('test.foreign', 'foreign', SecurityWeaknessSeverity::High, SecurityFindingSource::Builtin, 'Title', 'Summary')]);
    $registry = new SecurityWeaknessRegistry;
    $registry->registerCheck($check);

    $scan = new RunSecurityWeaknessScanAction($registry, new NullSecurityWeaknessAnalyser)->handle(false);
    expect($scan->failures)->toContain('check: test.registered: invalid finding')
        ->and(SecurityWeaknessFinding::query()->count())->toBe(0)
        ->and(SecurityScanRun::query()->findOrFail($scan->scanId)->findings_count)->toBeNull();
});

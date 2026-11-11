<?php

declare(strict_types=1);

use Capell\Core\Enums\Diagnostics\DoctorCheckSeverity;
use Capell\Core\Enums\Security\SecurityFindingStatus;
use Capell\Core\Enums\Security\SecurityWeaknessSeverity;
use Capell\Core\Models\SecurityScanRun;
use Capell\Core\Models\SecurityWeaknessFinding;
use Capell\Core\Support\Diagnostics\Checks\SecurityPostureCheck;
use Illuminate\Support\Str;

it('reports critical and high open findings with the appropriate urgency', function (): void {
    $check = new SecurityPostureCheck;
    $never = $check->check();
    expect($never->passed)->toBeTrue()
        ->and($never->severity)->toBe(DoctorCheckSeverity::Warning)
        ->and($never->evidence['never_scanned'])->toBeTrue()
        ->and($never->message)->toContain('capell:security:scan');
    $high = SecurityWeaknessFinding::factory()->create(['severity' => SecurityWeaknessSeverity::High]);
    expect($check->check()->severity)->toBe(DoctorCheckSeverity::Critical)
        ->and($check->check()->passed)->toBeFalse();
    $critical = SecurityWeaknessFinding::factory()->create(['severity' => SecurityWeaknessSeverity::Critical]);
    expect($check->check()->severity)->toBe(DoctorCheckSeverity::Critical);
    $high->update(['status' => SecurityFindingStatus::Resolved]);
    $critical->update(['status' => SecurityFindingStatus::Dismissed]);
    expect($check->check()->passed)->toBeTrue()
        ->and($check->check()->severity)->toBe(DoctorCheckSeverity::Warning)
        ->and($check->check()->evidence['never_scanned'])->toBeTrue();
});

it('passes when a completed scan has no findings', function (): void {
    SecurityScanRun::query()->create([
        'id' => (string) Str::ulid(), 'started_at' => now(),
        'finished_at' => now(), 'findings_count' => 0, 'source' => 'system:console',
    ]);
    $result = (new SecurityPostureCheck)->check();
    expect($result->passed)->toBeTrue()
        ->and($result->severity)->toBe(DoctorCheckSeverity::Info);
});

it('warns about migration-shaped findings while retaining their severity and counts', function (): void {
    SecurityWeaknessFinding::factory()->create(['severity' => SecurityWeaknessSeverity::Critical]);
    $result = (new SecurityPostureCheck)->check();
    expect($result->severity)->toBe(DoctorCheckSeverity::Critical)
        ->and($result->passed)->toBeFalse()
        ->and($result->evidence['critical'])->toBe(1)
        ->and($result->evidence['never_scanned'])->toBeTrue()
        ->and($result->message)->toContain(__('capell-core::security.doctor.never_scanned'));
});

it('warns when the latest completed scan failed after a successful scan', function (): void {
    SecurityScanRun::query()->create(['id' => (string) Str::ulid(), 'started_at' => now()->subHour(), 'finished_at' => now()->subHour(), 'findings_count' => 0, 'source' => 'test']);
    $time = now()->subMinute()->format('Y-m-d H:i:s');
    SecurityScanRun::query()->create(['id' => (string) Str::ulid(), 'started_at' => $time, 'finished_at' => $time, 'findings_count' => null, 'source' => 'test']);
    SecurityWeaknessFinding::factory()->create(['severity' => SecurityWeaknessSeverity::High]);
    $result = (new SecurityPostureCheck)->check();
    expect($result->severity)->toBe(DoctorCheckSeverity::Critical)
        ->and($result->passed)->toBeFalse()
        ->and($result->evidence['high'])->toBe(1)
        ->and($result->message)->toContain($time);
});

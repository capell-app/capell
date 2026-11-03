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
    expect($check->check()->severity)->toBe(DoctorCheckSeverity::Warning)
        ->and($check->check()->passed)->toBeFalse();
    $critical = SecurityWeaknessFinding::factory()->create(['severity' => SecurityWeaknessSeverity::Critical]);
    expect($check->check()->severity)->toBe(DoctorCheckSeverity::Critical);
    $high->update(['status' => SecurityFindingStatus::Resolved]);
    $critical->update(['status' => SecurityFindingStatus::Dismissed]);
    expect($check->check()->passed)->toBeTrue()
        ->and($check->check()->severity)->toBe(DoctorCheckSeverity::Info)
        ->and($check->check()->evidence)->not->toHaveKey('never_scanned');
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

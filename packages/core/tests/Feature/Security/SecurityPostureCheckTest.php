<?php

declare(strict_types=1);

use Capell\Core\Enums\Diagnostics\DoctorCheckSeverity;
use Capell\Core\Enums\Security\SecurityFindingStatus;
use Capell\Core\Enums\Security\SecurityWeaknessSeverity;
use Capell\Core\Models\SecurityWeaknessFinding;
use Capell\Core\Support\Diagnostics\Checks\SecurityPostureCheck;

it('reports critical and high open findings with the appropriate urgency', function (): void {
    $check = new SecurityPostureCheck;
    expect($check->check()->passed)->toBeTrue();
    $high = SecurityWeaknessFinding::factory()->create(['severity' => SecurityWeaknessSeverity::High]);
    expect($check->check()->severity)->toBe(DoctorCheckSeverity::Warning)
        ->and($check->check()->passed)->toBeFalse();
    $critical = SecurityWeaknessFinding::factory()->create(['severity' => SecurityWeaknessSeverity::Critical]);
    expect($check->check()->severity)->toBe(DoctorCheckSeverity::Critical);
    $high->update(['status' => SecurityFindingStatus::Resolved]);
    $critical->update(['status' => SecurityFindingStatus::Dismissed]);
    expect($check->check()->passed)->toBeTrue();
});

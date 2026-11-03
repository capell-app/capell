<?php

declare(strict_types=1);

namespace Capell\Core\Support\Diagnostics\Checks;

use Capell\Core\Contracts\DoctorCheck;
use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
use Capell\Core\Enums\Diagnostics\DoctorCheckSeverity;
use Capell\Core\Enums\Security\SecurityFindingStatus;
use Capell\Core\Enums\Security\SecurityWeaknessSeverity;
use Capell\Core\Models\SecurityScanRun;
use Capell\Core\Models\SecurityWeaknessFinding;
use Illuminate\Support\Facades\Schema;
use Override;

final class SecurityPostureCheck implements DoctorCheck
{
    #[Override]
    public function check(bool $installSummary = false): DoctorCheckResultData
    {
        $table = (new SecurityWeaknessFinding)->getTable();
        $counts = Schema::hasTable($table) ? SecurityWeaknessFinding::query()->where('status', SecurityFindingStatus::Open->value)
            ->selectRaw('severity, count(*) as aggregate')->groupBy('severity')->pluck('aggregate', 'severity')->all() : [];
        $hasFindings = Schema::hasTable($table) && SecurityWeaknessFinding::query()->exists();
        $scanTable = (new SecurityScanRun)->getTable();
        $hasScan = Schema::hasTable($scanTable) && SecurityScanRun::query()->whereNotNull('finished_at')->whereNotNull('findings_count')->exists();
        if (! $hasFindings && ! $hasScan) {
            return new DoctorCheckResultData(
                label: __('capell-core::security.doctor.label'),
                passed: true,
                message: __('capell-core::security.doctor.never_scanned'),
                remediation: __('capell-core::security.doctor.action'),
                id: 'core.security.posture',
                severity: DoctorCheckSeverity::Warning,
                evidence: ['never_scanned' => true, 'warning_count' => 1],
            );
        }

        $critical = (int) ($counts[SecurityWeaknessSeverity::Critical->value] ?? 0);
        $high = (int) ($counts[SecurityWeaknessSeverity::High->value] ?? 0);

        return new DoctorCheckResultData(
            label: __('capell-core::security.doctor.label'),
            passed: $critical === 0 && $high === 0,
            message: __('capell-core::security.doctor.message', ['critical' => $critical, 'high' => $high]),
            remediation: __('capell-core::security.doctor.action'),
            id: 'core.security.posture',
            severity: $critical > 0 ? DoctorCheckSeverity::Critical : ($high > 0 ? DoctorCheckSeverity::Warning : DoctorCheckSeverity::Info),
            evidence: ['critical' => $critical, 'high' => $high],
        );
    }
}

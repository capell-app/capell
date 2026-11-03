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
        $scanTable = (new SecurityScanRun)->getTable();
        $hasScan = Schema::hasTable($scanTable) && SecurityScanRun::query()->whereNotNull('finished_at')->whereNotNull('findings_count')->exists();
        $latest = Schema::hasTable($scanTable) ? SecurityScanRun::query()->whereNotNull('finished_at')->latest('started_at')->orderByDesc('id')->first() : null;
        $warning = $hasScan ? null : (string) __('capell-core::security.doctor.never_scanned');
        if ($latest !== null && $latest->findings_count === null) {
            $failedScan = (string) __('capell-core::security.doctor.failed_scan', ['time' => (string) $latest->finished_at]);
            $warning = trim(($warning ?? '') . ' ' . $failedScan);
        }

        $critical = (int) ($counts[SecurityWeaknessSeverity::Critical->value] ?? 0);
        $high = (int) ($counts[SecurityWeaknessSeverity::High->value] ?? 0);

        $message = (string) __('capell-core::security.doctor.message', ['critical' => $critical, 'high' => $high]);

        return new DoctorCheckResultData(
            label: __('capell-core::security.doctor.label'),
            passed: $critical === 0 && $high === 0,
            message: trim($message . ' ' . ($warning ?? '')),
            remediation: __('capell-core::security.doctor.action'),
            id: 'core.security.posture',
            severity: $critical > 0 ? DoctorCheckSeverity::Critical : ($high > 0 || $warning !== null ? DoctorCheckSeverity::Warning : DoctorCheckSeverity::Info),
            evidence: array_filter(['critical' => $critical, 'high' => $high, 'never_scanned' => $hasScan ? null : true, 'warning_count' => $warning !== null ? 1 : null], static fn (int|true|null $value): bool => $value !== null),
        );
    }
}

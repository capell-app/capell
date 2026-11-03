<?php

declare(strict_types=1);

namespace Capell\Core\Support\Diagnostics\Checks;

use Capell\Core\Contracts\DoctorCheck;
use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
use Capell\Core\Enums\Diagnostics\DoctorCheckSeverity;
use Capell\Core\Enums\Security\SecurityFindingStatus;
use Capell\Core\Enums\Security\SecurityRemediationOutcome;
use Capell\Core\Enums\Security\SecurityWeaknessSeverity;
use Capell\Core\Models\SecurityRemediationRun;
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
        $critical = (int) ($counts[SecurityWeaknessSeverity::Critical->value] ?? 0);
        $high = (int) ($counts[SecurityWeaknessSeverity::High->value] ?? 0);

        $message = __('capell-core::security.doctor.message', ['critical' => $critical, 'high' => $high]);
        $evidence = ['critical' => $critical, 'high' => $high];
        $pendingNodes = $this->pendingNodes();
        if ($pendingNodes !== null) {
            $message .= ' ' . __('capell-core::security.doctor.pending_nodes', ['count' => $pendingNodes['runs'], 'nodes' => implode(', ', $pendingNodes['nodes'])]);
            $evidence['pending_remediations'] = $pendingNodes['runs'];
            $evidence['pending_nodes'] = $pendingNodes['nodes'];
        }

        return new DoctorCheckResultData(
            label: __('capell-core::security.doctor.label'),
            passed: $critical === 0 && $high === 0 && $pendingNodes === null,
            message: $message,
            remediation: __('capell-core::security.doctor.action'),
            id: 'core.security.posture',
            severity: $critical > 0 ? DoctorCheckSeverity::Critical : ($high > 0 || $pendingNodes !== null ? DoctorCheckSeverity::Warning : DoctorCheckSeverity::Info),
            evidence: $evidence,
        );
    }

    /** @return array{runs: int, nodes: list<string>}|null */
    private function pendingNodes(): ?array
    {
        if (! Schema::hasTable((new SecurityRemediationRun)->getTable())) {
            return null;
        }

        $runs = SecurityRemediationRun::query()->where('outcome', SecurityRemediationOutcome::AppliedPendingNodes->value)->get(['expected_nodes', 'acknowledged_nodes']);
        if ($runs->isEmpty()) {
            return null;
        }

        $nodes = array_values(array_unique($runs->flatMap(fn (SecurityRemediationRun $run): array => array_values(array_diff($run->expected_nodes ?? [], $run->acknowledged_nodes ?? [])))->all()));
        sort($nodes);

        return ['runs' => $runs->count(), 'nodes' => $nodes];
    }
}

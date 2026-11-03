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
use Capell\Core\Support\Security\SecurityNodeRegistry;
use Illuminate\Support\Facades\Schema;
use Override;
use Throwable;

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
        $collisions = [];
        $registryUnknown = false;
        if (resolve(SecurityNodeRegistry::class)->enabled()) {
            try {
                $collisions = resolve(SecurityNodeRegistry::class)->collisions();
                resolve(SecurityNodeRegistry::class)->activeNodes();
            } catch (Throwable) {
                $registryUnknown = true;
            }
        }

        foreach ($collisions as $node) {
            $message .= ' ' . __('capell-core::security.doctor.node_collision', ['node' => $node]);
        }

        $pendingNodes = $this->pendingNodes($registryUnknown);
        foreach ($pendingNodes['unknown'] ?? [] as $runId) {
            $message .= ' ' . __('capell-core::security.doctor.unknown_fleet', ['run' => $runId]);
        }

        if ($pendingNodes !== null) {
            $message .= ' ' . __('capell-core::security.doctor.pending_nodes', ['count' => $pendingNodes['runs'], 'nodes' => implode(', ', $pendingNodes['nodes'])]);
            $evidence['pending_remediations'] = $pendingNodes['runs'];
            $evidence['pending_nodes'] = $pendingNodes['nodes'];
        }

        return new DoctorCheckResultData(
            label: __('capell-core::security.doctor.label'),
            passed: $critical === 0 && $high === 0 && $pendingNodes === null && $collisions === [],
            message: $message,
            remediation: __('capell-core::security.doctor.action'),
            id: 'core.security.posture',
            severity: $critical > 0 ? DoctorCheckSeverity::Critical : ($high > 0 || $pendingNodes !== null || $collisions !== [] ? DoctorCheckSeverity::Warning : DoctorCheckSeverity::Info),
            evidence: $evidence,
        );
    }

    /** @return array{runs: int, nodes: list<string>, unknown: list<int>}|null */
    private function pendingNodes(bool $registryUnknown): ?array
    {
        if (! Schema::hasTable((new SecurityRemediationRun)->getTable())) {
            return null;
        }

        $runs = SecurityRemediationRun::query()->where('outcome', SecurityRemediationOutcome::AppliedPendingNodes->value)->get(['id', 'expected_nodes', 'acknowledged_nodes']);
        if ($runs->isEmpty()) {
            return null;
        }

        $waitingByRun = [];
        foreach ($runs as $run) {
            $expected = SecurityNodeRegistry::parseNodeList($run->expected_nodes);
            $acknowledged = SecurityNodeRegistry::parseNodeList($run->acknowledged_nodes, true);
            if ($expected !== null && $acknowledged !== null && array_diff($acknowledged, $expected) === []) {
                $waitingByRun[$run->id] = array_values(array_diff($expected, $acknowledged));
            }
        }

        $unknown = [];
        foreach ($runs as $run) {
            if ($registryUnknown || ! isset($waitingByRun[$run->id])) {
                $unknown[] = $run->id;
            }
        }

        $nodes = array_values(array_unique(array_merge([], ...array_values($waitingByRun))));
        sort($nodes);

        return ['runs' => $runs->count(), 'nodes' => $nodes, 'unknown' => $unknown];
    }
}

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
use Capell\Core\Models\SecurityScanRun;
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
        $evidence = array_filter(['critical' => $critical, 'high' => $high, 'never_scanned' => $hasScan ? null : true, 'warning_count' => $warning !== null ? 1 : null], static fn (int|true|null $value): bool => $value !== null);
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
            $collision = (string) __('capell-core::security.doctor.node_collision', ['node' => $node]);
            $message .= ' ' . $collision;
        }

        $pendingNodes = $this->pendingNodes($registryUnknown);
        foreach ($pendingNodes['unknown'] ?? [] as $runId) {
            $unknownFleet = (string) __('capell-core::security.doctor.unknown_fleet', ['run' => $runId]);
            $message .= ' ' . $unknownFleet;
        }

        if ($pendingNodes !== null) {
            $pending = (string) __('capell-core::security.doctor.pending_nodes', ['count' => $pendingNodes['runs'], 'nodes' => implode(', ', $pendingNodes['nodes'])]);
            $message .= ' ' . $pending;
            $evidence['pending_remediations'] = $pendingNodes['runs'];
            $evidence['pending_nodes'] = $pendingNodes['nodes'];
        }

        $fleetIssue = $pendingNodes !== null || $collisions !== [];

        return new DoctorCheckResultData(
            label: __('capell-core::security.doctor.label'),
            passed: $critical === 0 && $high === 0 && ! $fleetIssue,
            message: trim($message . ' ' . ($warning ?? '')),
            remediation: __('capell-core::security.doctor.action'),
            id: 'core.security.posture',
            severity: $critical > 0 ? DoctorCheckSeverity::Critical : ($high > 0 || $warning !== null || $fleetIssue ? DoctorCheckSeverity::Warning : DoctorCheckSeverity::Info),
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

<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Security;

use Capell\Core\Enums\Security\SecurityFindingStatus;
use Capell\Core\Enums\Security\SecurityRemediationOutcome;
use Capell\Core\Events\SecurityRemediationApplied;
use Capell\Core\Models\SecurityRemediationRun;
use Capell\Core\Support\Security\SecurityNodeRegistry;
use Capell\Core\Support\Security\SecurityOutputSanitiser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

/**
 * Settles remediation runs waiting on other nodes: completes them once every
 * live node has acknowledged, and re-opens the finding, naming the nodes, when
 * a node left the fleet or the grace period ran out.
 */
class EvaluatePendingSecurityRemediationsAction
{
    use AsFake;
    use AsObject;

    public function __construct(private readonly SecurityNodeRegistry $nodes) {}

    /** @return int the number of runs settled */
    public function handle(): int
    {
        if (! $this->nodes->enabled()) {
            return 0;
        }

        $runIds = array_map(intval(...), SecurityRemediationRun::query()->where('outcome', SecurityRemediationOutcome::AppliedPendingNodes->value)->pluck('id')->all());
        if ($runIds === []) {
            return 0;
        }

        try {
            $active = $this->nodes->activeNodes();
            $retired = $this->nodes->retiredNodes();
        } catch (Throwable) {
            return 0;
        }

        $grace = max(0, (int) config('capell.security.node_refresh_grace_minutes', 60));
        $settled = 0;
        foreach ($runIds as $runId) {
            $completed = DB::transaction(function () use ($runId, $active, $retired, $grace): ?SecurityRemediationRun {
                $run = SecurityRemediationRun::query()->lockForUpdate()->whereKey($runId)->first();
                if ($run === null || $run->outcome !== SecurityRemediationOutcome::AppliedPendingNodes) {
                    return null;
                }

                $expected = SecurityNodeRegistry::parseNodeList($run->expected_nodes);
                $acknowledged = SecurityNodeRegistry::parseNodeList($run->acknowledged_nodes, true);
                if ($expected === null || $acknowledged === null || array_diff($acknowledged, $expected) !== []) {
                    return null;
                }

                $expected = array_values(array_diff(array_unique([...$expected, ...$active]), $retired));
                $acknowledged = array_values(array_diff($acknowledged, $retired));
                $run->acknowledged_nodes = $acknowledged;
                sort($expected);
                $run->expected_nodes = $expected;
                $run->save();

                $waiting = array_values(array_diff($expected, $acknowledged));
                $expired = $run->started_at->addMinutes($grace)->lte(CarbonImmutable::now());
                if ($waiting !== [] && ! $expired) {
                    return null;
                }

                $finding = $run->finding;
                if ($waiting === []) {
                    $run->outcome = SecurityRemediationOutcome::Applied;
                    $run->message = __('capell-core::security.remediation.changed');
                    $finding?->update(['status' => SecurityFindingStatus::Remediated, 'remediated_at' => CarbonImmutable::now()]);
                } else {
                    $missing = $waiting;
                    sort($missing);
                    $run->outcome = SecurityRemediationOutcome::Failed;
                    $run->message = new SecurityOutputSanitiser()->text(__('capell-core::security.remediation.nodes_not_refreshed', ['nodes' => implode(', ', $missing)]), 1000);
                    $finding?->update(['status' => SecurityFindingStatus::Open, 'remediated_at' => null, 'resolved_at' => null]);
                }

                $run->finished_at = CarbonImmutable::now();
                $run->save();

                return $run;
            });

            if ($completed instanceof SecurityRemediationRun) {
                $settled++;
                if ($completed->outcome === SecurityRemediationOutcome::Applied) {
                    event(new SecurityRemediationApplied($completed));
                }
            }
        }

        return $settled;
    }
}

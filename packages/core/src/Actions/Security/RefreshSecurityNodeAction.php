<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Security;

use Capell\Core\Contracts\Security\SecurityWeaknessRemediation;
use Capell\Core\Data\Security\SecurityWeaknessFindingData;
use Capell\Core\Enums\Security\SecurityRemediationOutcome;
use Capell\Core\Models\SecurityRemediationRun;
use Capell\Core\Support\Security\SecurityNodeRegistry;
use Capell\Core\Support\Security\SecurityWeaknessRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

/**
 * Registers this node and repeats every remediation still waiting on it, so a
 * node with its own .env applies the approved values before acknowledging.
 */
class RefreshSecurityNodeAction
{
    use AsFake;
    use AsObject;

    public function __construct(private readonly SecurityNodeRegistry $nodes, private readonly SecurityWeaknessRegistry $registry) {}

    /** @return int the number of runs this node acknowledged */
    public function handle(): int
    {
        if (! $this->nodes->enabled()) {
            return 0;
        }

        $this->nodes->register();
        $self = $this->nodes->nodeId();
        $acknowledged = 0;
        $runs = SecurityRemediationRun::query()->with('finding')->where('outcome', SecurityRemediationOutcome::AppliedPendingNodes->value)->get()
            ->filter(fn (SecurityRemediationRun $run): bool => in_array($self, $run->expected_nodes ?? [], true) && ! in_array($self, $run->acknowledged_nodes ?? [], true));
        foreach ($runs as $run) {
            if ($this->applyLocally($run) && $this->acknowledge($run->id, $self)) {
                $acknowledged++;
            }
        }

        return $acknowledged;
    }

    private function applyLocally(SecurityRemediationRun $run): bool
    {
        $finding = $run->finding;
        $remediation = $this->registry->remediation($run->weakness_id);
        if ($finding === null || ! $remediation instanceof SecurityWeaknessRemediation || $remediation::class !== $run->remediation_class) {
            return false;
        }

        try {
            // Idempotent: on shared storage the values are already set and only the cache is cleared.
            $outcome = $remediation->apply(new SecurityWeaknessFindingData(
                weaknessId: $finding->weakness_id,
                fingerprint: $finding->fingerprint,
                severity: $finding->severity,
                source: $finding->source,
                title: $finding->title,
                summary: $finding->summary,
                evidence: $finding->evidence ?? [],
                composerName: $finding->composer_name,
                remediable: $finding->remediable,
                recommendedAction: $finding->recommended_action,
                confidence: $finding->confidence,
            ));

            return ! $outcome->failed && Artisan::call('config:clear') === 0;
        } catch (Throwable) {
            return false;
        }
    }

    private function acknowledge(int $runId, string $self): bool
    {
        return DB::transaction(function () use ($runId, $self): bool {
            $run = SecurityRemediationRun::query()->lockForUpdate()->find($runId);
            if ($run === null || $run->outcome !== SecurityRemediationOutcome::AppliedPendingNodes) {
                return false;
            }

            $acknowledged = $run->acknowledged_nodes ?? [];
            if (! in_array($self, $acknowledged, true)) {
                $acknowledged[] = $self;
                sort($acknowledged);
                $run->acknowledged_nodes = $acknowledged;
                $run->save();
            }

            return true;
        });
    }
}

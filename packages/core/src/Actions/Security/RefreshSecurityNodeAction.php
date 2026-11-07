<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Security;

use Capell\Core\Contracts\Security\SecurityWeaknessRemediation;
use Capell\Core\Data\Security\SecurityNodeRefreshResultData;
use Capell\Core\Data\Security\SecurityWeaknessFindingData;
use Capell\Core\Enums\Security\SecurityRemediationOutcome;
use Capell\Core\Models\SecurityRemediationApproval;
use Capell\Core\Models\SecurityRemediationRun;
use Capell\Core\Support\Security\SecurityNodeRegistry;
use Capell\Core\Support\Security\SecurityOutputSanitiser;
use Capell\Core\Support\Security\SecurityWeaknessRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

final class RefreshSecurityNodeAction
{
    use AsFake;
    use AsObject;

    public function __construct(private readonly SecurityNodeRegistry $nodes, private readonly SecurityWeaknessRegistry $registry) {}

    public function handle(): SecurityNodeRefreshResultData
    {
        if (! $this->nodes->enabled()) {
            return new SecurityNodeRefreshResultData(0, []);
        }

        $this->nodes->register();
        $self = $this->nodes->nodeId();
        $acknowledged = 0;
        $failures = [];
        $runs = SecurityRemediationRun::query()->where('outcome', SecurityRemediationOutcome::AppliedPendingNodes->value)->get();
        foreach ($runs as $candidate) {
            $result = DB::transaction(function () use ($candidate, $self): ?string {
                $run = SecurityRemediationRun::query()->lockForUpdate()->find($candidate->id);
                if ($run === null || $run->outcome !== SecurityRemediationOutcome::AppliedPendingNodes) {
                    return null;
                }

                $expectedNodes = SecurityNodeRegistry::parseNodeList($run->expected_nodes);
                $acknowledgedNodes = SecurityNodeRegistry::parseNodeList($run->acknowledged_nodes, true);
                if ($expectedNodes === null || $acknowledgedNodes === null || array_diff($acknowledgedNodes, $expectedNodes) !== []) {
                    return __('capell-core::security.remediation.failed');
                }

                if (! in_array($self, $expectedNodes, true) || in_array($self, $acknowledgedNodes, true)) {
                    return null;
                }

                $approval = SecurityRemediationApproval::query()->where('weakness_id', $run->weakness_id)->lockForUpdate()->first();
                if ($approval === null || ! $approval->isActive() || $approval->remediation_class !== $run->remediation_class) {
                    $reason = __('capell-core::security.remediation.approval_revoked');
                    $run->outcome = SecurityRemediationOutcome::Failed;
                    $run->message = $reason;
                    $run->finished_at = CarbonImmutable::now();
                    $this->recordFailure($run, $self, $reason);

                    return $reason;
                }

                $finding = $run->finding;
                $remediation = $this->registry->remediation($run->weakness_id);
                $reason = __('capell-core::security.remediation.failed');
                if ($finding !== null && $remediation instanceof SecurityWeaknessRemediation && $remediation::class === $run->remediation_class) {
                    try {
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
                        $reason = new SecurityOutputSanitiser()->text($outcome->message, 1000);
                        if (! $outcome->failed && ($outcome->applied || $outcome->alreadyCompliant)) {
                            if (Artisan::call('config:clear') === 0) {
                                $nodes = $run->acknowledged_nodes;
                                $nodes[] = $self;
                                sort($nodes);
                                $run->acknowledged_nodes = $nodes;
                                $run->save();

                                return '';
                            }

                            $reason = __('capell-core::security.remediation.cache_refresh_required');
                        }
                    } catch (Throwable) {
                        $reason = __('capell-core::security.remediation.failed');
                    }
                }

                $this->recordFailure($run, $self, $reason);

                return $reason;
            });
            if ($result === '') {
                $acknowledged++;
            } elseif ($result !== null) {
                $failures[] = $result;
            }
        }

        return new SecurityNodeRefreshResultData($acknowledged, $failures);
    }

    private function recordFailure(SecurityRemediationRun $run, string $node, string $reason): void
    {
        $outcomes = $run->node_outcomes ?? [];
        $outcomes[$node] = $reason;
        $run->node_outcomes = $outcomes;
        $run->save();
    }
}

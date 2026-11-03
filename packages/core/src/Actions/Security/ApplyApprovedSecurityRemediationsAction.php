<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Security;

use Capell\Core\Contracts\Security\SecurityWeaknessRemediation;
use Capell\Core\Data\Security\SecurityRemediationBatchResultData;
use Capell\Core\Data\Security\SecurityWeaknessFindingData;
use Capell\Core\Enums\Security\SecurityFindingStatus;
use Capell\Core\Enums\Security\SecurityRemediationOutcome;
use Capell\Core\Events\SecurityRemediationApplied;
use Capell\Core\Models\SecurityRemediationApproval;
use Capell\Core\Models\SecurityRemediationRun;
use Capell\Core\Models\SecurityWeaknessFinding;
use Capell\Core\Support\Security\SecurityNodeRegistry;
use Capell\Core\Support\Security\SecurityOutputSanitiser;
use Capell\Core\Support\Security\SecurityWeaknessRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;
use Throwable;

class ApplyApprovedSecurityRemediationsAction
{
    use AsFake;
    use AsObject;

    public function __construct(private readonly SecurityWeaknessRegistry $registry, private readonly SecurityNodeRegistry $nodes = new SecurityNodeRegistry) {}

    /**
     * @param  list<string>  $failedChecks  weakness ids whose check failed in the latest scan; their findings are skipped
     */
    public function handle(string $actor, bool $dryRun = false, ?string $onlyWeaknessId = null, ?CarbonImmutable $detectedSince = null, array $failedChecks = []): SecurityRemediationBatchResultData
    {
        $multiNode = ! $dryRun && $this->nodes->enabled();
        if ($multiNode) {
            // Refuses a node-local cache store before any setting changes.
            $this->nodes->register();
        }

        $query = SecurityWeaknessFinding::query()->where('status', SecurityFindingStatus::Open->value);
        if ($onlyWeaknessId !== null) {
            $query->where('weakness_id', $onlyWeaknessId);
        }

        $considered = 0;
        $applied = 0;
        $failed = 0;
        $runIds = [];
        $skipped = [];
        $failureMessages = [];
        foreach ($query->get() as $candidate) {
            $considered++;
            $result = DB::transaction(function () use ($candidate, $actor, $dryRun, $detectedSince, $failedChecks, $multiNode): array {
                $finding = SecurityWeaknessFinding::query()->lockForUpdate()->find($candidate->id);
                if ($finding === null || $finding->status !== SecurityFindingStatus::Open) {
                    return ['skip' => 'no longer open'];
                }

                if (! $finding->source->mayRemediateAutomatically()) {
                    return ['skip' => __('capell-core::security.remediation.advice_only')];
                }

                if (in_array($finding->weakness_id, $failedChecks, true) || ($detectedSince instanceof CarbonImmutable && $finding->last_detected_at->lt($detectedSince))) {
                    return ['skip' => __('capell-core::security.remediation.not_confirmed')];
                }

                $remediation = $this->registry->remediation($finding->weakness_id);
                $approval = SecurityRemediationApproval::query()->where('weakness_id', $finding->weakness_id)->lockForUpdate()->first();
                if (! $finding->remediable || ! $remediation instanceof SecurityWeaknessRemediation) {
                    return ['skip' => 'not eligible'];
                }

                if ($approval === null || ! $approval->isActive()) {
                    return ['skip' => 'approval required'];
                }

                if ($approval->remediation_class !== $remediation::class) {
                    return ['skip' => __('capell-core::security.remediation.fix_changed')];
                }

                if ($dryRun) {
                    return ['skip' => 'dry-run'];
                }

                if ($multiNode && SecurityRemediationRun::query()->where('finding_id', $finding->id)->where('outcome', SecurityRemediationOutcome::AppliedPendingNodes->value)->exists()) {
                    return ['skip' => __('capell-core::security.remediation.pending_nodes_skip')];
                }

                $run = SecurityRemediationRun::query()->create([
                    'finding_id' => $finding->id, 'weakness_id' => $finding->weakness_id,
                    'remediation_class' => $remediation::class, 'actor' => $actor,
                    'outcome' => SecurityRemediationOutcome::Skipped, 'message' => __('capell-core::security.remediation.started'),
                    'started_at' => CarbonImmutable::now(),
                ]);
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
                    $sanitiser = new SecurityOutputSanitiser;
                    $run->fill(['outcome' => $outcome->failed ? SecurityRemediationOutcome::Failed : ($outcome->applied ? SecurityRemediationOutcome::Applied : SecurityRemediationOutcome::Skipped),
                        'message' => $sanitiser->text($outcome->message, 1000), 'before' => $sanitiser->context($outcome->before), 'after' => $sanitiser->context($outcome->after)]);
                    // Also when already set here: a retry must still reach the other nodes.
                    if ($multiNode && ! $outcome->failed && $outcome->nodeLocal) {
                        $this->awaitOtherNodes($run);
                    }

                    if ($run->outcome === SecurityRemediationOutcome::Applied) {
                        $finding->update(['status' => SecurityFindingStatus::Remediated, 'remediated_at' => CarbonImmutable::now()]);
                    }
                } catch (Throwable) {
                    // Exceptions may contain secrets; retain a safe diagnostic only.
                    $run->fill(['outcome' => SecurityRemediationOutcome::Failed, 'message' => __('capell-core::security.remediation.failed')]);
                }

                $run->finished_at = CarbonImmutable::now();
                $run->save();

                return ['run' => $run];
            });
            if (isset($result['skip'])) {
                $skipped[$candidate->fingerprint] = $result['skip'];

                continue;
            }

            $run = $result['run'];
            if ($run->outcome === SecurityRemediationOutcome::Applied) {
                event(new SecurityRemediationApplied($run));
            }

            $runIds[] = $run->id;
            $applied += (int) ($run->outcome === SecurityRemediationOutcome::Applied);
            $failed += (int) ($run->outcome === SecurityRemediationOutcome::Failed);
            if ($run->outcome === SecurityRemediationOutcome::Failed) {
                $failureMessages[$candidate->fingerprint] = $run->message;
            }

            if ($run->outcome === SecurityRemediationOutcome::Skipped) {
                $skipped[$candidate->fingerprint] = $run->message;
            }
        }

        return new SecurityRemediationBatchResultData($dryRun, $considered, $applied, $failed, $runIds, $skipped, $failureMessages);
    }

    /**
     * Snapshots the live fleet; the finding stays open until every other node
     * has applied the same change and acknowledged it.
     */
    private function awaitOtherNodes(SecurityRemediationRun $run): void
    {
        $self = $this->nodes->nodeId();
        $expected = $this->nodes->activeNodes();
        $previous = SecurityRemediationRun::query()->where('finding_id', $run->finding_id)
            ->where('id', '!=', $run->id)->whereNotNull('expected_nodes')->latest('id')->first();
        if ($previous !== null) {
            $previousExpected = SecurityNodeRegistry::parseNodeList($previous->expected_nodes);
            $previousAcknowledged = SecurityNodeRegistry::parseNodeList($previous->acknowledged_nodes, true);
            throw_if($previousExpected === null || $previousAcknowledged === null || array_diff($previousAcknowledged, $previousExpected) !== [], RuntimeException::class, 'The previous fleet membership is unknown.');

            $expected = array_values(array_unique([...$expected, ...array_diff($previousExpected, $previousAcknowledged)]));
            sort($expected);
        }

        $expected = array_values(array_diff($expected, $this->nodes->retiredNodes()));
        if (! in_array($self, $expected, true)) {
            $expected[] = $self;
            sort($expected);
        }

        if ($expected === [$self]) {
            return;
        }

        $run->fill([
            'outcome' => SecurityRemediationOutcome::AppliedPendingNodes,
            'message' => __('capell-core::security.remediation.pending_nodes', ['count' => count($expected) - 1]),
            'expected_nodes' => $expected,
            'acknowledged_nodes' => [$self],
        ]);
    }
}

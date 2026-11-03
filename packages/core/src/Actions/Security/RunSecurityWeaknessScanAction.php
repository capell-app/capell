<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Security;

use Capell\Core\Contracts\Security\SecurityWeaknessAnalyser;
use Capell\Core\Data\Security\SecurityScanResultData;
use Capell\Core\Data\Security\SecurityWeaknessFindingData;
use Capell\Core\Enums\Security\SecurityFindingSource;
use Capell\Core\Enums\Security\SecurityFindingStatus;
use Capell\Core\Enums\Security\SecurityRemediationOutcome;
use Capell\Core\Events\SecurityWeaknessDetected;
use Capell\Core\Models\SecurityRemediationRun;
use Capell\Core\Models\SecurityWeaknessFinding;
use Capell\Core\Support\Security\SecurityNodeRegistry;
use Capell\Core\Support\Security\SecurityOutputSanitiser;
use Capell\Core\Support\Security\SecurityWeaknessRegistry;
use Carbon\CarbonImmutable;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

class RunSecurityWeaknessScanAction
{
    use AsFake;
    use AsObject;

    public function __construct(private readonly SecurityWeaknessRegistry $registry, private readonly SecurityWeaknessAnalyser $analyser, private readonly SecurityNodeRegistry $nodes = new SecurityNodeRegistry) {}

    public function handle(bool $includeAnalyser = true): SecurityScanResultData
    {
        $now = CarbonImmutable::now();
        $inventory = BuildSecurityPostureInventoryAction::run();
        $sanitiser = new SecurityOutputSanitiser;
        $failures = $inventory->failures;
        $findings = [];
        $checksRun = 0;
        foreach ($this->registry->checks() as $id => $check) {
            $checksRun++;
            try {
                foreach ($check->detect($inventory) as $finding) {
                    if (! SecurityWeaknessRegistry::isValid($finding->weaknessId)) {
                        $failures[] = 'check: ' . $id . ': invalid finding';

                        continue;
                    }

                    $finding = $this->normaliseIdentifiers($finding);
                    $findings[$finding->fingerprint] = $finding;
                }
            } catch (Throwable) {
                $failures[] = 'check: ' . $id;
            }
        }

        $analyserUsed = null;
        if ($includeAnalyser && config('capell.security.analyser.enabled', true) === true) {
            try {
                if ($this->analyser->isAvailable()) {
                    $analyserUsed = $sanitiser->text($this->analyser->name(), 200);
                    foreach ($this->analyser->analyse($inventory) as $finding) {
                        if (! SecurityWeaknessRegistry::isValid($finding->weaknessId)) {
                            $failures[] = 'analyser: invalid finding';

                            continue;
                        }

                        $finding = $this->normaliseIdentifiers($finding);
                        if (isset($findings[$finding->fingerprint])) {
                            continue;
                        }

                        $findings[$finding->fingerprint] = new SecurityWeaknessFindingData(
                            weaknessId: $finding->weaknessId,
                            fingerprint: $finding->fingerprint,
                            severity: $finding->severity,
                            source: SecurityFindingSource::Analyser,
                            title: $sanitiser->text($finding->title, 200),
                            summary: $sanitiser->text($finding->summary, 2000),
                            evidence: $sanitiser->context($finding->evidence),
                            composerName: $finding->composerName,
                            remediable: false,
                            recommendedAction: $finding->recommendedAction === null ? null : $sanitiser->text($finding->recommendedAction, 2000),
                            confidence: $finding->confidence,
                            detectedAt: $finding->detectedAt,
                        );
                    }
                }
            } catch (Throwable) {
                $failures[] = 'analyser: ' . $this->analyser::class;
            }
        }

        $new = 0;
        $reopened = 0;
        foreach ($findings as $data) {
            $finding = SecurityWeaknessFinding::query()->firstOrNew(['fingerprint' => $data->fingerprint]);
            $isNew = ! $finding->exists;
            $wasResolved = ! $isNew && in_array($finding->status, [SecurityFindingStatus::Resolved, SecurityFindingStatus::Remediated], true);
            $finding->fill([
                'weakness_id' => $data->weaknessId, 'source' => $data->source, 'severity' => $data->severity,
                'title' => $sanitiser->text($data->title, 200), 'summary' => $sanitiser->text($data->summary, 2000), 'evidence' => $sanitiser->context($data->evidence),
                'composer_name' => $data->composerName, 'remediable' => $data->remediable,
                'recommended_action' => $data->recommendedAction === null ? null : $sanitiser->text($data->recommendedAction, 2000), 'confidence' => $data->confidence,
                'last_detected_at' => $now,
            ]);
            if ($isNew) {
                $finding->first_detected_at = $now;
                $finding->status = SecurityFindingStatus::Open;
                $new++;
            } elseif ($wasResolved) {
                $finding->status = SecurityFindingStatus::Open;
                $finding->resolved_at = null;
                $finding->remediated_at = null;
                $reopened++;
            }

            $finding->save();
            if ($isNew || $wasResolved) {
                event(new SecurityWeaknessDetected($finding));
            }
        }

        // A failed or omitted detector cannot establish that its old findings disappeared.
        $resolved = 0;
        if ($failures === []) {
            $query = SecurityWeaknessFinding::query()->active()->whereNotIn('fingerprint', array_keys($findings));
            if ($this->nodes->enabled()) {
                // This node may be fixed while others still carry the weakness.
                EvaluatePendingSecurityRemediationsAction::run();
                $query->whereNotIn('id', $this->findingsAwaitingNodes());
            }

            if (! $includeAnalyser || $analyserUsed === null) {
                $query->where('source', '!=', SecurityFindingSource::Analyser->value);
            }

            $resolved = $query->update(['status' => SecurityFindingStatus::Resolved->value, 'resolved_at' => $now]);
        }

        $active = SecurityWeaknessFinding::query()->active()->get();
        $highest = $active->sortByDesc(fn (SecurityWeaknessFinding $finding): int => $finding->severity->rank())->first()?->severity;

        return new SecurityScanResultData($now, $checksRun, $inventory->advisorySourcesRun, $analyserUsed, $new, $reopened, $resolved, $active->count(), $highest, $failures);
    }

    /**
     * Findings whose latest fleet-wide run is still pending, or ended without
     * every node applying it: only another approved run or an operator settles them.
     *
     * @return list<int>
     */
    private function findingsAwaitingNodes(): array
    {
        $candidates = SecurityRemediationRun::query()->whereNotNull('expected_nodes')->whereNotNull('finding_id')->distinct()->pluck('finding_id');

        return array_values(SecurityRemediationRun::query()->whereIn('finding_id', $candidates)
            ->orderBy('id')->get(['id', 'finding_id', 'outcome', 'expected_nodes'])
            ->keyBy('finding_id')
            ->filter(fn (SecurityRemediationRun $run): bool => $run->expected_nodes !== null
                && in_array($run->outcome, [SecurityRemediationOutcome::AppliedPendingNodes, SecurityRemediationOutcome::Failed], true))
            ->map(fn (SecurityRemediationRun $run): int => (int) $run->finding_id)
            ->all());
    }

    private function normaliseIdentifiers(SecurityWeaknessFindingData $finding): SecurityWeaknessFindingData
    {
        $fingerprint = preg_match('/\A[a-f0-9]{64}\z/', $finding->fingerprint) === 1
            ? $finding->fingerprint : hash('sha256', $finding->fingerprint);
        $composerName = $finding->composerName;
        if ($composerName !== null && (strlen($composerName) > 255 || preg_match('/\A[a-z0-9](?:[_.-]?[a-z0-9]+)*\/[a-z0-9](?:(?:[_.]|-{1,2})?[a-z0-9]+)*\z/', $composerName) !== 1)) {
            $composerName = null;
        }

        return new SecurityWeaknessFindingData(
            weaknessId: $finding->weaknessId,
            fingerprint: $fingerprint,
            severity: $finding->severity,
            source: $finding->source,
            title: $finding->title,
            summary: $finding->summary,
            evidence: $finding->evidence,
            composerName: $composerName,
            remediable: $finding->remediable,
            recommendedAction: $finding->recommendedAction,
            confidence: $finding->confidence,
            detectedAt: $finding->detectedAt,
        );
    }
}

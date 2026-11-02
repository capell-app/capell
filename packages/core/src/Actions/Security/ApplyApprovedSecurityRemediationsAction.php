<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Security;

use Capell\Core\Contracts\Security\SecurityWeaknessRemediation;
use Capell\Core\Data\Security\SecurityRemediationBatchResultData;
use Capell\Core\Data\Security\SecurityWeaknessFindingData;
use Capell\Core\Enums\Security\SecurityFindingSource;
use Capell\Core\Enums\Security\SecurityFindingStatus;
use Capell\Core\Enums\Security\SecurityRemediationOutcome;
use Capell\Core\Events\SecurityRemediationApplied;
use Capell\Core\Models\SecurityRemediationApproval;
use Capell\Core\Models\SecurityRemediationRun;
use Capell\Core\Models\SecurityWeaknessFinding;
use Capell\Core\Support\Security\SecurityWeaknessRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

class ApplyApprovedSecurityRemediationsAction
{
    use AsFake;
    use AsObject;

    public function __construct(private readonly SecurityWeaknessRegistry $registry) {}

    public function handle(string $actor, bool $dryRun = false, ?string $onlyWeaknessId = null): SecurityRemediationBatchResultData
    {
        $query = SecurityWeaknessFinding::query()->where('status', SecurityFindingStatus::Open->value);
        if ($onlyWeaknessId !== null) {
            $query->where('weakness_id', $onlyWeaknessId);
        }

        $considered = 0;
        $applied = 0;
        $failed = 0;
        $runIds = [];
        $skipped = [];
        foreach ($query->get() as $candidate) {
            $considered++;
            $result = DB::transaction(function () use ($candidate, $actor, $dryRun): array {
                $finding = SecurityWeaknessFinding::query()->lockForUpdate()->find($candidate->id);
                if ($finding === null || $finding->status !== SecurityFindingStatus::Open) {
                    return ['skip' => 'no longer open'];
                }

                $remediation = $this->registry->remediation($finding->weakness_id);
                $approval = SecurityRemediationApproval::query()->where('weakness_id', $finding->weakness_id)->lockForUpdate()->first();
                if (! $finding->remediable || ! $remediation instanceof SecurityWeaknessRemediation || (! $finding->source->mayRemediateAutomatically() && $finding->source !== SecurityFindingSource::Analyser)) {
                    return ['skip' => 'not eligible'];
                }

                if ($approval === null || ! $approval->isActive()) {
                    return ['skip' => 'approval required'];
                }

                if ($dryRun) {
                    return ['skip' => 'dry-run'];
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
                    $run->fill(['outcome' => $outcome->applied ? SecurityRemediationOutcome::Applied : SecurityRemediationOutcome::Skipped,
                        'message' => $outcome->message, 'before' => $outcome->before, 'after' => $outcome->after]);
                    if ($outcome->applied) {
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
            if ($run->outcome === SecurityRemediationOutcome::Skipped) {
                $skipped[$candidate->fingerprint] = $run->message;
            }
        }

        return new SecurityRemediationBatchResultData($dryRun, $considered, $applied, $failed, $runIds, $skipped);
    }
}

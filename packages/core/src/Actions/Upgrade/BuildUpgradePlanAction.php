<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Upgrade;

use Capell\Core\Contracts\UpgradeStepContract;
use Capell\Core\Data\UpgradeContext;
use Capell\Core\Data\UpgradePlanData;
use Capell\Core\Enums\Upgrade\UpgradeStepStatus;
use Capell\Core\Models\UpgradeLogEntry;
use Illuminate\Database\Query\JoinClause;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class BuildUpgradePlanAction
{
    use AsFake;
    use AsObject;

    public function handle(bool $dryRun = false, string $triggeredBy = 'upgrade'): UpgradePlanData
    {
        $composerVersions = ResolveInstalledComposerVersionsAction::run();

        $ledgerVersions = $this->lastKnownVersions();

        $appliedStepIds = UpgradeLogEntry::query()
            ->steps()
            ->where('status', UpgradeStepStatus::Success->value)
            ->pluck('key')
            ->unique()
            ->values()
            ->all();

        $context = new UpgradeContext(
            composerVersions: $composerVersions,
            ledgerVersions: $ledgerVersions,
            appliedStepIds: $appliedStepIds,
            dryRun: $dryRun,
            triggeredBy: $triggeredBy,
        );

        $allSteps = iterator_to_array(app()->tagged('capell.upgrade-steps'));

        $pending = array_values(array_filter(
            $allSteps,
            static fn (UpgradeStepContract $step): bool => ! in_array($step->id(), $appliedStepIds, true)
                && $step->shouldRun($context),
        ));

        return new UpgradePlanData(
            pendingSteps: SortUpgradeStepsAction::run($pending),
            context: $context,
            versionAudit: AuditInstalledVersionsAction::run($composerVersions),
        );
    }

    /**
     * @return array<string, string>
     */
    private function lastKnownVersions(): array
    {
        $latestRanAtByKey = UpgradeLogEntry::query()
            ->versionSnapshots()
            ->select('key')
            ->selectRaw('MAX(ran_at) as latest_ran_at')
            ->groupBy('key');

        $latestSnapshotIds = UpgradeLogEntry::query()
            ->versionSnapshots()
            ->joinSub($latestRanAtByKey, 'latest_version_snapshots', function (JoinClause $join): void {
                $join
                    ->on('capell_upgrade_log.key', '=', 'latest_version_snapshots.key')
                    ->on('capell_upgrade_log.ran_at', '=', 'latest_version_snapshots.latest_ran_at');
            })
            ->selectRaw('MAX(capell_upgrade_log.id) as id')
            ->groupBy('capell_upgrade_log.key')
            ->pluck('id')
            ->all();

        $rows = UpgradeLogEntry::query()
            ->whereIn('id', $latestSnapshotIds)
            ->orderBy('key')
            ->get(['key', 'meta']);

        $latest = [];

        foreach ($rows as $row) {
            if (array_key_exists($row->key, $latest)) {
                continue;
            }

            $version = $row->metaGet('to_version');
            if (is_string($version)) {
                $latest[$row->key] = $version;
            }
        }

        return $latest;
    }
}

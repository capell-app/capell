<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Upgrade;

use Capell\Core\Contracts\UpgradeStepContract;
use Capell\Core\Data\UpgradeContext;
use Capell\Core\Data\UpgradePlanData;
use Capell\Core\Enums\Upgrade\UpgradeStepStatus;
use Capell\Core\Models\UpgradeLogEntry;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

class BuildUpgradePlanAction
{
    use AsFake;
    use AsObject;

    public function handle(bool $dryRun = false, string $triggeredBy = 'upgrade'): UpgradePlanData
    {
        $composerVersions = ResolveInstalledComposerVersionsAction::run();

        $ledgerVersions = ReadLatestInstalledVersionsAction::run();

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
}

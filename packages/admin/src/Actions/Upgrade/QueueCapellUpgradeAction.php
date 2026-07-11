<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Upgrade;

use Capell\Admin\Jobs\RunCapellUpgradeJob;
use Capell\Core\Actions\Upgrade\BuildUpgradeReadinessReportAction;
use Capell\Core\Actions\Upgrade\CreateUpgradeRunAction;
use Capell\Core\Data\Upgrade\UpgradeQueueResultData;
use Capell\Core\Data\Upgrade\UpgradeReadinessReportData;
use Capell\Core\Data\UpgradeRunOptions;
use Capell\Core\Enums\Upgrade\UpgradeManualCommand;
use Capell\Core\Enums\Upgrade\UpgradeQueueStatus;
use Capell\Core\Enums\Upgrade\UpgradeRunStatus;
use Capell\Core\Models\UpgradeRun;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Lorisleiva\Actions\Concerns\AsObject;

final class QueueCapellUpgradeAction
{
    use AsObject;

    public function handle(bool $dryRun = false, ?int $userId = null): UpgradeQueueResultData
    {
        $options = new UpgradeRunOptions(
            dryRun: $dryRun,
            force: true,
            noClearCache: true,
        );
        $readiness = BuildUpgradeReadinessReportAction::run();
        $manualCommands = [
            UpgradeManualCommand::DryRun->value,
            UpgradeManualCommand::Run->value,
        ];
        $authenticatedUserId = auth()->id();
        $status = $readiness->canQueue() ? UpgradeRunStatus::Queued : UpgradeRunStatus::ManualRequired;

        if (! Schema::hasTable('capell_upgrade_runs') || ! Schema::hasTable('capell_upgrade_run_events')) {
            return new UpgradeQueueResultData(
                runId: null,
                runStatus: UpgradeRunStatus::ManualRequired,
                queueStatus: UpgradeQueueStatus::ManualRequired,
                readiness: $readiness,
                manualCommands: $manualCommands,
            );
        }

        try {
            $result = Cache::lock('capell:upgrade-queue', 10)->block(
                5,
                fn (): UpgradeQueueResultData => $this->createOrReturnRun(
                    options: $options,
                    readiness: $readiness,
                    status: $status,
                    manualCommands: $manualCommands,
                    userId: $userId ?? (is_int($authenticatedUserId) ? $authenticatedUserId : null),
                ),
            );

            if ($result instanceof UpgradeQueueResultData) {
                return $result;
            }
        } catch (LockTimeoutException) {
            $activeRun = UpgradeRun::query()->active()->latest('created_at')->first();

            if ($activeRun instanceof UpgradeRun) {
                return $this->existingRunResult($activeRun, $readiness, $manualCommands);
            }

            return new UpgradeQueueResultData(
                runId: null,
                runStatus: UpgradeRunStatus::ManualRequired,
                queueStatus: UpgradeQueueStatus::ManualRequired,
                readiness: $readiness,
                manualCommands: $manualCommands,
            );
        }

        return new UpgradeQueueResultData(
            runId: null,
            runStatus: UpgradeRunStatus::ManualRequired,
            queueStatus: UpgradeQueueStatus::ManualRequired,
            readiness: $readiness,
            manualCommands: $manualCommands,
        );
    }

    /**
     * @param  list<string>  $manualCommands
     */
    private function createOrReturnRun(
        UpgradeRunOptions $options,
        UpgradeReadinessReportData $readiness,
        UpgradeRunStatus $status,
        array $manualCommands,
        ?int $userId,
    ): UpgradeQueueResultData {
        $activeRun = UpgradeRun::query()->active()->latest('created_at')->first();

        if ($activeRun instanceof UpgradeRun) {
            return $this->existingRunResult($activeRun, $readiness, $manualCommands);
        }

        $run = CreateUpgradeRunAction::run(
            options: $options,
            readiness: $readiness,
            status: $status,
            manualCommands: $manualCommands,
            userId: $userId,
        );

        if ($status === UpgradeRunStatus::Queued) {
            dispatch(new RunCapellUpgradeJob((int) $run->getKey()));
        }

        return new UpgradeQueueResultData(
            runId: (int) $run->getKey(),
            runStatus: $status,
            queueStatus: $status === UpgradeRunStatus::Queued ? UpgradeQueueStatus::Queued : UpgradeQueueStatus::ManualRequired,
            readiness: $readiness,
            manualCommands: $manualCommands,
        );
    }

    /**
     * @param  list<string>  $manualCommands
     */
    private function existingRunResult(UpgradeRun $run, UpgradeReadinessReportData $readiness, array $manualCommands): UpgradeQueueResultData
    {
        return new UpgradeQueueResultData(
            runId: (int) $run->getKey(),
            runStatus: $run->status,
            queueStatus: UpgradeQueueStatus::Queued,
            readiness: $readiness,
            manualCommands: $manualCommands,
        );
    }
}

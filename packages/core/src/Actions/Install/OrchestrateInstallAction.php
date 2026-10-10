<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Contracts\InstallOrchestrationHost;
use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Data\Install\InstallOrchestrationData;
use Capell\Core\Data\Install\InstallRunResultData;
use Capell\Core\Data\InstallInputData;
use Capell\Core\Support\Install\InstallPlan;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

final class OrchestrateInstallAction
{
    use AsFake;
    use AsObject;

    public function __construct(
        private readonly RunInstallAction $runInstall,
        private readonly ClearCachesAction $clearCaches,
    ) {}

    public function handle(
        InstallInputData $inputData,
        InstallOrchestrationData $orchestration,
        ProgressReporter $reporter,
        InstallOrchestrationHost $host,
    ): void {
        PreflightExtraPackagesAction::run($inputData->extraPackages, $reporter);
        if ($orchestration->outputPlan) {
            $host->outputPlan($inputData);
        }

        $host->prepareApplication($inputData, $reporter);

        $result = $this->runInstall->runWithResult($inputData, $reporter);
        // Required packages and panel scaffolding can register patches only
        // during installation. Complete them before the final asset build.
        $host->prepareApplication($inputData, $reporter);
        $host->upgradeFilament();

        if ($orchestration->runNpmBuild) {
            try {
                $host->buildFrontendAssets();
            } catch (Throwable $failure) {
                ReportInstallFailureAction::run($inputData, $result->completedSteps, InstallPlan::STEP_REBUILD_RESOURCES, $reporter);

                throw $failure;
            }

            $result = new InstallRunResultData(
                selectedPackages: $result->selectedPackages,
                completedSteps: array_values(array_unique([...$result->completedSteps, InstallPlan::STEP_REBUILD_RESOURCES])),
                doctorStatus: $result->doctorStatus,
            );
        }

        if ($orchestration->removeInstaller) {
            $host->removeInstaller();
        }

        $cachesToClear = in_array('all', $orchestration->cachesToClear, true)
            ? $orchestration->cachesToClear
            : array_values(array_unique([...$orchestration->cachesToClear, 'packages']));

        $this->clearCaches->handle($cachesToClear, $reporter);
        $host->reportManualChanges();
        $host->finalizeInstall($inputData, $result);
    }
}

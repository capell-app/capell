<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Contracts\InstallOrchestrationHost;
use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Data\Install\InstallOrchestrationData;
use Capell\Core\Data\InstallInputData;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class OrchestrateInstallAction
{
    use AsFake;
    use AsObject;

    public function __construct(
        private readonly PreflightExtraPackagesAction $preflightExtraPackages,
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
        $host->prepareApplication($inputData, $reporter);

        if ($orchestration->outputPlan) {
            $host->outputPlan($inputData);
        }

        RunInstallAction::run($inputData, $reporter);
        $host->upgradeFilament();

        if ($orchestration->runNpmBuild) {
            $host->buildFrontendAssets();
        }

        if ($orchestration->removeInstaller) {
            $host->removeInstaller();
        }

        ClearCachesAction::run($orchestration->cachesToClear, $reporter);
        $host->reportManualChanges();
        $host->finalizeInstall();
    }
}

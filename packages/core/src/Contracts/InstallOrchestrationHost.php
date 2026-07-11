<?php

declare(strict_types=1);

namespace Capell\Core\Contracts;

use Capell\Core\Data\InstallInputData;

interface InstallOrchestrationHost
{
    public function prepareApplication(InstallInputData $inputData, ProgressReporter $reporter): void;

    public function outputPlan(InstallInputData $inputData): void;

    public function upgradeFilament(): void;

    public function buildFrontendAssets(): void;

    public function removeInstaller(): void;

    public function reportManualChanges(): void;

    public function finalizeInstall(): void;
}

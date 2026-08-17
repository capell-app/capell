<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Data\Install\RunInstallStepResultData;
use Capell\Core\Data\InstallInputData;
use Capell\Core\Support\Install\InstallRunState;
use Capell\Core\Support\Install\InstallStepExecutor;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

class RunInstallStepAction
{
    use AsFake;
    use AsObject;

    /**
     * Run a single installation step.
     *
     * Each call rebuilds the run state from scratch, so callers that drive
     * steps one HTTP request at a time (the browser installer) must persist
     * and re-supply resolvedUserId and packageMetadataRefreshed themselves —
     * this action only reports what changed during this one step.
     */
    public function handle(
        string $stepKey,
        InstallInputData $inputData,
        ProgressReporter $reporter,
        ?int $resolvedUserId = null,
        bool $packageMetadataRefreshed = false,
    ): RunInstallStepResultData {
        $state = new InstallRunState($inputData, $reporter, $resolvedUserId, $packageMetadataRefreshed);

        resolve(InstallStepExecutor::class)->execute($stepKey, $state);

        return new RunInstallStepResultData(
            resolvedUserId: $state->resolvedUserId(),
            packageMetadataRefreshed: $state->packageMetadataIsRefreshed(),
        );
    }
}

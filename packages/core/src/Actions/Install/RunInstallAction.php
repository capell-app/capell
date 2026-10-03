<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Data\Install\InstallRunResultData;
use Capell\Core\Data\InstallInputData;
use Capell\Core\Support\Install\InstallPlan;
use Capell\Core\Support\Install\InstallRunState;
use Capell\Core\Support\Install\InstallStepExecutor;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

class RunInstallAction
{
    use AsFake;
    use AsObject;

    private ?InstallRunResultData $completedResult = null;

    public function handle(InstallInputData $inputData, ProgressReporter $reporter): void
    {
        $this->completedResult = null;
        $reporter->step('Starting installation…');

        $state = new InstallRunState($inputData, $reporter);
        $executor = resolve(InstallStepExecutor::class);
        $plan = InstallPlan::build($inputData);
        $completedSteps = [];

        while (($step = collect($plan)->first(fn (array $step): bool => ! in_array($step['key'], $completedSteps, true))) !== null) {
            $reporter->step(sprintf('[%d/%d] %s', count($completedSteps) + 1, count($plan), $step['label']));
            $executor->execute($step['key'], $state);
            $completedSteps[] = $step['key'];

            if (InstallPlan::isPackageRequireStep($step['key']) || $step['key'] === InstallPlan::STEP_INSTALL_DEVELOPER_TOOLING) {
                $plan = InstallPlan::refreshPackageSteps($inputData, $plan, $completedSteps);
            }
        }

        $this->completedResult = BuildInstallRunResultAction::run($inputData, $completedSteps);
    }

    public function runWithResult(InstallInputData $inputData, ProgressReporter $reporter): InstallRunResultData
    {
        $this->completedResult = null;
        $this->handle($inputData, $reporter);

        // Existing custom handle implementations retain their result fallback.
        return $this->completedResult ?? BuildInstallRunResultAction::run($inputData);
    }
}

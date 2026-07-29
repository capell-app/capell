<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Data\Install\InstallStepData;
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

    public function handle(InstallInputData $inputData, ProgressReporter $reporter): void
    {
        $reporter->step('Starting installation…');

        $state = new InstallRunState($inputData, $reporter);
        $executor = resolve(InstallStepExecutor::class);
        $steps = InstallPlan::steps($inputData);
        $totalSteps = $steps->count();

        $steps->each(function (InstallStepData $step, int $index) use ($executor, $reporter, $state, $totalSteps): void {
            $reporter->step(sprintf('[%d/%d] %s', $index + 1, $totalSteps, $step->label));

            $executor->execute($step->key, $state);
        });
    }
}

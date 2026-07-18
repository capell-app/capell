<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Data\InstallInputData;
use Capell\Core\Support\Install\InstallRunState;
use Capell\Core\Support\Install\InstallStepExecutor;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class RunInstallStepAction
{
    use AsFake;
    use AsObject;

    /**
     * Run a single installation step.
     *
     * Returns the (possibly updated) resolved user id, which downstream steps may need.
     */
    public function handle(
        string $stepKey,
        InstallInputData $inputData,
        ProgressReporter $reporter,
        ?int $resolvedUserId = null,
    ): ?int {
        $state = new InstallRunState($inputData, $reporter, $resolvedUserId);

        resolve(InstallStepExecutor::class)->execute($stepKey, $state);

        return $state->resolvedUserId();
    }
}

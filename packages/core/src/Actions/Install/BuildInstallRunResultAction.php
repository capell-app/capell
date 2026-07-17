<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Data\Install\InstallRunResultData;
use Capell\Core\Data\InstallInputData;
use Capell\Core\Support\Install\InstallPlan;
use Lorisleiva\Actions\Concerns\AsObject;

final class BuildInstallRunResultAction
{
    use AsObject;

    public function handle(InstallInputData $inputData): InstallRunResultData
    {
        $selectedPackages = collect([
            ...$inputData->packages,
            ...$inputData->extraPackages,
        ])
            ->filter(fn (mixed $package): bool => is_string($package) && $package !== '')
            ->unique()
            ->sort()
            ->values()
            ->all();

        return new InstallRunResultData(
            selectedPackages: $selectedPackages,
            completedSteps: InstallPlan::steps($inputData)->pluck('key')->all(),
            doctorStatus: 'passed',
        );
    }
}

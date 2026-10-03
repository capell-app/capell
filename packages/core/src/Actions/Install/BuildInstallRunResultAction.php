<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Data\Install\InstallRunResultData;
use Capell\Core\Data\InstallInputData;
use Capell\Core\Support\Install\InstallPlan;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class BuildInstallRunResultAction
{
    use AsFake;
    use AsObject;

    /** @param list<string>|null $completedSteps */
    public function handle(InstallInputData $inputData, ?array $completedSteps = null): InstallRunResultData
    {
        $selectedPackages = array_values(collect([
            ...$inputData->packages,
            ...$inputData->extraPackages,
        ])
            ->filter(fn (mixed $package): bool => is_string($package) && $package !== '')
            ->unique()
            ->sort()
            ->values()
            ->all());

        return new InstallRunResultData(
            selectedPackages: $selectedPackages,
            completedSteps: $completedSteps ?? array_values(InstallPlan::steps($inputData)->pluck('key')->all()),
            doctorStatus: 'passed',
        );
    }
}

<?php

declare(strict_types=1);

namespace Capell\Core\Support\Diagnostics\Checks;

use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
use Capell\Core\Data\PackageData;
use Capell\Core\Enums\Diagnostics\DoctorCheckSeverity;
use Capell\Core\Facades\CapellCore;

final class InstalledPackagesCheck extends AbstractDoctorCheck
{
    protected function id(): string
    {
        return 'core.packages.installed';
    }

    protected function severity(): DoctorCheckSeverity
    {
        return DoctorCheckSeverity::Critical;
    }

    protected function run(bool $installSummary): DoctorCheckResultData
    {
        $packages = CapellCore::getPackages(withoutCore: false)
            ->filter(fn (PackageData $package): bool => $package->isInstalled())
            ->keys();

        if ($packages->isEmpty()) {
            return new DoctorCheckResultData(
                label: 'Installed Capell packages',
                passed: false,
                message: 'No installed Capell packages were detected.',
                remediation: 'Run php artisan capell:install and choose the required packages.',
            );
        }

        return new DoctorCheckResultData('Installed Capell packages', true, sprintf('%d package(s) are marked installed.', $packages->count()));
    }
}

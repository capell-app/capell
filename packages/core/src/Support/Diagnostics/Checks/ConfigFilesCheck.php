<?php

declare(strict_types=1);

namespace Capell\Core\Support\Diagnostics\Checks;

use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
use Capell\Core\Enums\Diagnostics\DoctorCheckSeverity;

final class ConfigFilesCheck extends AbstractDoctorCheck
{
    protected function id(): string
    {
        return 'core.config.published';
    }

    protected function severity(): DoctorCheckSeverity
    {
        return DoctorCheckSeverity::Info;
    }

    protected function run(bool $installSummary): DoctorCheckResultData
    {
        $files = glob(config_path('capell*.php'));

        if ($files === false || $files === []) {
            return new DoctorCheckResultData('Config files', true, 'No published Capell config files detected (defaults in use).');
        }

        return new DoctorCheckResultData(
            label: 'Config files',
            passed: true,
            message: sprintf('Published config file(s) detected: %s.', implode(', ', array_map(basename(...), $files))),
            remediation: 'Keep published config files in sync when upgrading.',
        );
    }
}

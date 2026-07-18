<?php

declare(strict_types=1);

namespace Capell\Core\Support\Diagnostics\Checks;

use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
use Capell\Core\Enums\Diagnostics\DoctorCheckSeverity;

final class GeneratedTailwindCssCheck extends AbstractDoctorCheck
{
    protected function id(): string
    {
        return 'core.tailwind.generated';
    }

    protected function severity(): DoctorCheckSeverity
    {
        return DoctorCheckSeverity::Warning;
    }

    protected function run(bool $installSummary): DoctorCheckResultData
    {
        $paths = array_values(array_unique(array_filter([
            resource_path('css/capell/frontend.css'),
            public_path('vendor/capell-frontend/css/frontend.css'),
        ], fn (string $path): bool => $path !== '')));

        foreach ($paths as $path) {
            if (is_file($path)) {
                return new DoctorCheckResultData('Generated frontend Tailwind CSS', true, sprintf('Found %s.', str_replace(base_path() . DIRECTORY_SEPARATOR, '', $path)));
            }
        }

        if (! app()->bound('capell.tailwind.generator')) {
            return new DoctorCheckResultData('Generated frontend Tailwind CSS', true, 'No frontend Tailwind generator is registered for this install.');
        }

        return new DoctorCheckResultData(
            'Generated frontend Tailwind CSS',
            false,
            'No generated Capell frontend CSS file was found.',
            'Run php artisan capell:frontend-install, then npm run build if the application Vite bundle is not current.',
        );
    }
}

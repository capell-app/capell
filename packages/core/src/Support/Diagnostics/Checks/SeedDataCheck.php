<?php

declare(strict_types=1);

namespace Capell\Core\Support\Diagnostics\Checks;

use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
use Capell\Core\Enums\Diagnostics\DoctorCheckSeverity;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Override;
use Throwable;

final class SeedDataCheck extends AbstractDoctorCheck
{
    #[Override]
    protected function id(): string
    {
        return 'core.seed-data.present';
    }

    #[Override]
    protected function severity(): DoctorCheckSeverity
    {
        return DoctorCheckSeverity::Critical;
    }

    #[Override]
    protected function run(bool $installSummary): DoctorCheckResultData
    {
        $issues = [];

        try {
            if (Site::query()->count() === 0) {
                $issues[] = 'No sites found';
            }
        } catch (Throwable) {
            $issues[] = 'Could not query sites table';
        }

        try {
            if (Language::query()->count() === 0) {
                $issues[] = 'No languages found';
            }
        } catch (Throwable) {
            $issues[] = 'Could not query languages table';
        }

        return $issues !== []
            ? new DoctorCheckResultData('Seed data is present', false, implode('; ', $issues) . '.', 'Default content setup has not completed. Run php artisan capell:install with Capell Admin and Capell Frontend selected, reuse the existing administrator, and do not use --no-seed-default-data or --fresh. Composer installing these packages does not run their setup lifecycles.')
            : new DoctorCheckResultData('Seed data is present', true, 'At least one site and language exist.');
    }
}

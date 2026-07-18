<?php

declare(strict_types=1);

namespace Capell\Core\Support\Diagnostics\Checks;

use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
use Capell\Core\Enums\Diagnostics\DoctorCheckSeverity;
use Capell\Core\Models\Layout;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\Schema;

final class DefaultThemeAndLayoutCheck extends AbstractDoctorCheck
{
    protected function id(): string
    {
        return 'core.defaults.theme-layout';
    }

    protected function severity(): DoctorCheckSeverity
    {
        return DoctorCheckSeverity::Critical;
    }

    protected function run(bool $installSummary): DoctorCheckResultData
    {
        $issues = [];

        if (Schema::hasTable('themes') && ! resolve(ConnectionResolverInterface::class)->table('themes')->where('default', true)->exists()) {
            $issues[] = 'No default theme';
        }

        if (Schema::hasTable('layouts') && ! Layout::query()->default()->exists()) {
            $issues[] = 'No default layout';
        }

        return $issues !== []
            ? new DoctorCheckResultData('Default theme and layout records', false, implode('; ', $issues) . '.', 'Rerun theme setup and ensure default theme/layout fixtures are seeded.')
            : new DoctorCheckResultData('Default theme and layout records', true, 'Default theme and layout records are present.');
    }
}

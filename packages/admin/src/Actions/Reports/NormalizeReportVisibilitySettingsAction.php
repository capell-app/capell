<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Reports;

use Capell\Admin\Facades\CapellAdmin;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class NormalizeReportVisibilitySettingsAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  array<int, array<string, mixed>>  $reportVisibility
     * @return array<string, array<string, bool>>
     */
    public function handle(array $reportVisibility): array
    {
        $registeredReportKeys = array_fill_keys(array_keys(CapellAdmin::getReports()), true);
        $settings = [];

        foreach ($reportVisibility as $roleState) {
            $roleName = is_string($roleState['role_name'] ?? null) ? $roleState['role_name'] : '';

            if ($roleName === '') {
                continue;
            }

            $reports = is_array($roleState['reports'] ?? null) ? $roleState['reports'] : [];

            foreach ($reports as $reportState) {
                if (! is_array($reportState)) {
                    continue;
                }

                $reportKey = is_string($reportState['report_key'] ?? null) ? $reportState['report_key'] : '';
                if ($reportKey === '') {
                    continue;
                }

                if (! isset($registeredReportKeys[$reportKey])) {
                    continue;
                }

                $settings[$roleName][$reportKey] = (bool) ($reportState['enabled'] ?? false);
            }
        }

        return $settings;
    }
}

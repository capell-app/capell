<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Reports;

use Capell\Admin\Data\Reports\ReportDefinitionData;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Settings\AdminSettings;
use Lorisleiva\Actions\Concerns\AsObject;
use Spatie\Permission\Models\Role;

final class BuildReportVisibilityFormStateAction
{
    use AsObject;

    /**
     * @return list<array{role_name: string, role_label: string, reports: list<array{report_key: string, report_label: string, report_description: string, enabled: bool}>}>
     */
    public function handle(?AdminSettings $settings = null): array
    {
        $settings ??= AdminSettings::instance();
        $reports = CapellAdmin::getReports();

        return array_values(Role::query()
            ->orderBy('name')
            ->pluck('name')
            ->filter(fn (mixed $roleName): bool => is_string($roleName) && $roleName !== '')
            ->values()
            ->map(fn (string $roleName): array => [
                'role_name' => $roleName,
                'role_label' => str($roleName)->replace('_', ' ')->headline()->toString(),
                'reports' => array_values(collect($reports)
                    ->map(fn (ReportDefinitionData $report): array => [
                        'report_key' => $report->key,
                        'report_label' => $report->resolvedLabel(),
                        'report_description' => $report->resolvedDescription(),
                        'enabled' => $settings->isReportEnabledForRole($roleName, $report),
                    ])
                    ->values()
                    ->all()),
            ])
            ->all());
    }
}

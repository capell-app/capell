<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Reports;

use Capell\Admin\Contracts\Reports\BuildsReportSnapshot;
use Capell\Admin\Data\Reports\ReportFindingData;
use Capell\Admin\Data\Reports\ReportMetricData;
use Capell\Admin\Data\Reports\ReportSnapshotData;
use Capell\Admin\Enums\Reports\ReportFindingSeverity;
use Capell\Core\Actions\Diagnostics\BuildDoctorReportAction;
use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
use Capell\Core\Data\PackageData;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Support\Database\RuntimeSchemaState;
use Illuminate\Database\ConnectionResolverInterface;
use Lorisleiva\Actions\Concerns\AsObject;

final class BuildDemoInstallHealthReportAction implements BuildsReportSnapshot
{
    use AsObject;

    private const string REPORT_KEY = 'core.demo_install_health';

    /**
     * Core doctor checks whose failure blocks a usable demo/install. All other
     * doctor failures surface as warnings so environment-specific gaps (unbuilt
     * frontend CSS, marketplace metadata, ...) never mask install blockers.
     *
     * @var list<string>
     */
    private const array CRITICAL_DOCTOR_CHECK_LABELS = [
        'Required tables exist',
        'Seed data is present',
        'Morph map is complete',
        'Admin user access',
        'Homepage route resolves',
        'Default theme and layout records',
    ];

    private const array EVENT_SOURCING_TABLES = ['stored_events', 'page_revisions'];

    public function __construct(
        private readonly BuildDoctorReportAction $buildDoctorReport,
        private readonly RuntimeSchemaState $schemaState,
        private readonly ConnectionResolverInterface $connections,
    ) {}

    public function handle(): ReportSnapshotData
    {
        if (! $this->schemaState->hasTable('sites') || ! $this->schemaState->hasTable('languages')) {
            return new ReportSnapshotData(
                key: self::REPORT_KEY,
                emptyState: __('capell-admin::reports.empty_state_demo_install_health'),
            );
        }

        $doctorFindings = [];
        $doctorChecks = $this->buildDoctorReport->handle(includePackageDoctors: false)->checks;

        foreach ($doctorChecks as $doctorCheck) {
            $finding = $this->findingForFailedCheck($doctorCheck, $this->doctorCheckSeverity($doctorCheck));

            if ($finding instanceof ReportFindingData) {
                $doctorFindings[] = $finding;
            }
        }

        $demoFindings = [];
        $demoChecksPassed = 0;
        $demoChecks = [
            [$this->storageLinkCheck(), ReportFindingSeverity::Warning],
            [$this->eventSourcingTablesCheck(), ReportFindingSeverity::Critical],
            [$this->settingsRowsCheck(), ReportFindingSeverity::Warning],
        ];

        foreach ($demoChecks as [$demoCheck, $severity]) {
            $finding = $this->findingForFailedCheck($demoCheck, $severity);

            if ($finding instanceof ReportFindingData) {
                $demoFindings[] = $finding;
            } else {
                $demoChecksPassed++;
            }
        }

        $checksPassed = $doctorChecks
            ->filter(fn (DoctorCheckResultData $doctorCheck): bool => $doctorCheck->passed)
            ->count() + $demoChecksPassed;
        $checksTotal = $doctorChecks->count() + count($demoChecks);

        return new ReportSnapshotData(
            key: self::REPORT_KEY,
            emptyState: __('capell-admin::reports.empty_state_demo_install_health'),
            metrics: [
                new ReportMetricData(
                    label: __('capell-admin::reports.demo_install_health_metric_sites'),
                    value: Site::query()->count(),
                    description: __('capell-admin::reports.demo_install_health_metric_sites_description'),
                ),
                new ReportMetricData(
                    label: __('capell-admin::reports.demo_install_health_metric_languages'),
                    value: Language::query()->count(),
                    description: __('capell-admin::reports.demo_install_health_metric_languages_description'),
                ),
                new ReportMetricData(
                    label: __('capell-admin::reports.demo_install_health_metric_pages'),
                    value: $this->schemaState->hasTable('pages') ? Page::query()->count() : 0,
                    description: __('capell-admin::reports.demo_install_health_metric_pages_description'),
                ),
                new ReportMetricData(
                    label: __('capell-admin::reports.demo_install_health_metric_installed_packages'),
                    value: $this->installedPackagesCount(),
                    description: __('capell-admin::reports.demo_install_health_metric_installed_packages_description'),
                ),
                new ReportMetricData(
                    label: __('capell-admin::reports.demo_install_health_metric_settings_rows'),
                    value: $this->settingsRowsCount(),
                    description: __('capell-admin::reports.demo_install_health_metric_settings_rows_description'),
                ),
                new ReportMetricData(
                    label: __('capell-admin::reports.demo_install_health_metric_checks_passed'),
                    value: sprintf('%d / %d', $checksPassed, $checksTotal),
                    description: __('capell-admin::reports.demo_install_health_metric_checks_passed_description'),
                ),
            ],
            findings: array_merge($doctorFindings, $demoFindings),
        );
    }

    private function findingForFailedCheck(DoctorCheckResultData $check, ReportFindingSeverity $severity): ?ReportFindingData
    {
        if ($check->passed) {
            return null;
        }

        $description = $check->message;

        if ($check->remediation !== null && $check->remediation !== '') {
            $description .= ' ' . __('capell-admin::reports.demo_install_health_finding_remediation', [
                'remediation' => $check->remediation,
            ]);
        }

        return new ReportFindingData(
            severity: $severity,
            title: $check->label,
            description: $description,
        );
    }

    private function doctorCheckSeverity(DoctorCheckResultData $check): ReportFindingSeverity
    {
        return in_array($check->label, self::CRITICAL_DOCTOR_CHECK_LABELS, true)
            ? ReportFindingSeverity::Critical
            : ReportFindingSeverity::Warning;
    }

    private function storageLinkCheck(): DoctorCheckResultData
    {
        $storagePath = public_path('storage');

        if (is_link($storagePath) || is_dir($storagePath)) {
            return new DoctorCheckResultData(
                label: __('capell-admin::reports.demo_install_health_check_storage_link'),
                passed: true,
                message: __('capell-admin::reports.demo_install_health_storage_link_present'),
            );
        }

        return new DoctorCheckResultData(
            label: __('capell-admin::reports.demo_install_health_check_storage_link'),
            passed: false,
            message: __('capell-admin::reports.demo_install_health_storage_link_missing'),
            remediation: __('capell-admin::reports.demo_install_health_storage_link_remediation'),
        );
    }

    private function eventSourcingTablesCheck(): DoctorCheckResultData
    {
        $missingTables = array_values(array_filter(
            self::EVENT_SOURCING_TABLES,
            fn (string $table): bool => ! $this->schemaState->hasTable($table),
        ));

        if ($missingTables !== []) {
            return new DoctorCheckResultData(
                label: __('capell-admin::reports.demo_install_health_check_event_sourcing'),
                passed: false,
                message: __('capell-admin::reports.demo_install_health_event_sourcing_missing', [
                    'tables' => implode(', ', $missingTables),
                ]),
                remediation: __('capell-admin::reports.demo_install_health_event_sourcing_remediation'),
            );
        }

        return new DoctorCheckResultData(
            label: __('capell-admin::reports.demo_install_health_check_event_sourcing'),
            passed: true,
            message: __('capell-admin::reports.demo_install_health_event_sourcing_present'),
        );
    }

    private function settingsRowsCheck(): DoctorCheckResultData
    {
        if ($this->settingsRowsCount() > 0) {
            return new DoctorCheckResultData(
                label: __('capell-admin::reports.demo_install_health_check_settings_rows'),
                passed: true,
                message: __('capell-admin::reports.demo_install_health_settings_rows_present'),
            );
        }

        return new DoctorCheckResultData(
            label: __('capell-admin::reports.demo_install_health_check_settings_rows'),
            passed: false,
            message: __('capell-admin::reports.demo_install_health_settings_rows_missing'),
            remediation: __('capell-admin::reports.demo_install_health_settings_rows_remediation'),
        );
    }

    private function settingsRowsCount(): int
    {
        if (! $this->schemaState->hasTable('settings')) {
            return 0;
        }

        return $this->connections->connection()->table('settings')->count();
    }

    private function installedPackagesCount(): int
    {
        return CapellCore::getPackages(withoutCore: false)
            ->filter(fn (PackageData $package): bool => $package->isInstalled())
            ->count();
    }
}

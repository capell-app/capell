<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Reports;

use Capell\Admin\Contracts\Reports\BuildsReportSnapshot;
use Capell\Admin\Data\Reports\ReportFindingData;
use Capell\Admin\Data\Reports\ReportMetricData;
use Capell\Admin\Data\Reports\ReportSnapshotData;
use Capell\Admin\Enums\Reports\ReportFindingSeverity;
use Capell\Core\Actions\Diagnostics\BuildDoctorReportAction;
use Capell\Core\Actions\Diagnostics\ResolveCapellInstallationStateAction;
use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
use Capell\Core\Data\PackageData;
use Capell\Core\Enums\Diagnostics\CapellInstallationState;
use Capell\Core\Enums\Diagnostics\DoctorCheckSeverity;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Support\Database\RuntimeSchemaState;
use Capell\Core\Support\Diagnostics\CapellRuntimeSchemaContract;
use Illuminate\Database\ConnectionResolverInterface;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class BuildDemoInstallHealthReportAction implements BuildsReportSnapshot
{
    use AsFake;
    use AsObject;

    private const string REPORT_KEY = 'core.demo_install_health';

    private const array EVENT_SOURCING_TABLES = ['stored_events', 'page_revisions'];

    public function __construct(
        private readonly BuildDoctorReportAction $buildDoctorReport,
        private readonly RuntimeSchemaState $schemaState,
        private readonly ConnectionResolverInterface $connections,
        private readonly ResolveCapellInstallationStateAction $resolveInstallationState,
        private readonly CapellRuntimeSchemaContract $runtimeSchema,
    ) {}

    public function handle(): ReportSnapshotData
    {
        $installationState = ResolveCapellInstallationStateAction::run();

        if ($installationState === CapellInstallationState::NotInstalled) {
            return new ReportSnapshotData(
                key: self::REPORT_KEY,
                emptyState: __('capell-admin::reports.empty_state_demo_install_health'),
            );
        }

        if ($installationState === CapellInstallationState::Partial) {
            $missingTables = $this->runtimeSchema->missingTables();

            return new ReportSnapshotData(
                key: self::REPORT_KEY,
                emptyState: __('capell-admin::reports.empty_state_demo_install_health'),
                findings: [new ReportFindingData(
                    severity: ReportFindingSeverity::Critical,
                    title: 'Required tables exist',
                    description: $missingTables !== []
                        ? sprintf('Missing tables: %s.', implode(', ', $missingTables))
                        : 'Core lifecycle state does not record a complete installation.',
                    id: 'core.schema.required',
                    remediation: 'Run php artisan migrate, then rerun the Capell installer if the Core lifecycle row is absent.',
                    evidence: [
                        'installation_state' => $installationState->value,
                        'missing_tables' => $missingTables,
                    ],
                )],
            );
        }

        $doctorFindings = [];
        $doctorChecks = BuildDoctorReportAction::run(includePackageDoctors: false)->checks;

        foreach ($doctorChecks as $doctorCheck) {
            $finding = $this->findingForFailedCheck($doctorCheck);

            if ($finding instanceof ReportFindingData) {
                $doctorFindings[] = $finding;
            }
        }

        $demoFindings = [];
        $demoChecksPassed = 0;
        $demoChecks = [
            $this->queueConnectionCheck(),
            $this->cacheStoreCheck(),
            $this->storageLinkCheck(),
            $this->eventSourcingTablesCheck(),
            $this->settingsRowsCheck(),
        ];

        foreach ($demoChecks as $demoCheck) {
            $finding = $this->findingForFailedCheck($demoCheck);

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

    private function findingForFailedCheck(DoctorCheckResultData $check): ?ReportFindingData
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
            severity: match ($check->severity) {
                DoctorCheckSeverity::Critical => ReportFindingSeverity::Critical,
                DoctorCheckSeverity::Warning => ReportFindingSeverity::Warning,
                DoctorCheckSeverity::Info => ReportFindingSeverity::Info,
            },
            title: $check->label,
            description: $description,
            id: $check->id,
            remediation: $check->remediation,
            evidence: $check->evidence,
        );
    }

    private function storageLinkCheck(): DoctorCheckResultData
    {
        $storagePath = public_path('storage');

        if (is_link($storagePath) || is_dir($storagePath)) {
            return new DoctorCheckResultData(
                label: __('capell-admin::reports.demo_install_health_check_storage_link'),
                passed: true,
                message: __('capell-admin::reports.demo_install_health_storage_link_present'),
                id: 'admin.storage-link',
                severity: DoctorCheckSeverity::Warning,
            );
        }

        return new DoctorCheckResultData(
            label: __('capell-admin::reports.demo_install_health_check_storage_link'),
            passed: false,
            message: __('capell-admin::reports.demo_install_health_storage_link_missing'),
            remediation: __('capell-admin::reports.demo_install_health_storage_link_remediation'),
            id: 'admin.storage-link',
            severity: DoctorCheckSeverity::Warning,
        );
    }

    private function queueConnectionCheck(): DoctorCheckResultData
    {
        $connection = config('queue.default');
        $driver = is_string($connection) ? config(sprintf('queue.connections.%s.driver', $connection)) : null;
        $passed = is_string($connection) && $connection !== '' && is_string($driver) && $driver !== '';

        return new DoctorCheckResultData(
            label: 'Queue connection is configured',
            passed: $passed,
            message: $passed
                ? sprintf('The [%s] queue connection uses the [%s] driver.', $connection, $driver)
                : 'The default queue connection does not resolve to a configured driver.',
            remediation: $passed ? null : 'Set QUEUE_CONNECTION to a configured queue connection, then restart workers.',
            id: 'core.queue.connection-configured',
            severity: DoctorCheckSeverity::Critical,
            evidence: [
                'connection' => $connection,
                'driver' => $driver,
            ],
        );
    }

    private function cacheStoreCheck(): DoctorCheckResultData
    {
        $store = config('cache.default');
        $driver = is_string($store) ? config(sprintf('cache.stores.%s.driver', $store)) : null;
        $passed = is_string($store) && $store !== '' && is_string($driver) && $driver !== '';

        return new DoctorCheckResultData(
            label: 'Cache store is configured',
            passed: $passed,
            message: $passed
                ? sprintf('The [%s] cache store uses the [%s] driver.', $store, $driver)
                : 'The default cache store does not resolve to a configured driver.',
            remediation: $passed ? null : 'Set CACHE_STORE to a configured cache store, then clear configuration cache.',
            id: 'core.cache.store-configured',
            severity: DoctorCheckSeverity::Critical,
            evidence: [
                'store' => $store,
                'driver' => $driver,
            ],
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
                id: 'core.schema.event-sourcing',
                severity: DoctorCheckSeverity::Critical,
                evidence: ['missing_tables' => $missingTables],
            );
        }

        return new DoctorCheckResultData(
            label: __('capell-admin::reports.demo_install_health_check_event_sourcing'),
            passed: true,
            message: __('capell-admin::reports.demo_install_health_event_sourcing_present'),
            id: 'core.schema.event-sourcing',
            severity: DoctorCheckSeverity::Critical,
        );
    }

    private function settingsRowsCheck(): DoctorCheckResultData
    {
        if ($this->settingsRowsCount() > 0) {
            return new DoctorCheckResultData(
                label: __('capell-admin::reports.demo_install_health_check_settings_rows'),
                passed: true,
                message: __('capell-admin::reports.demo_install_health_settings_rows_present'),
                id: 'admin.settings.rows',
                severity: DoctorCheckSeverity::Warning,
            );
        }

        return new DoctorCheckResultData(
            label: __('capell-admin::reports.demo_install_health_check_settings_rows'),
            passed: false,
            message: __('capell-admin::reports.demo_install_health_settings_rows_missing'),
            remediation: __('capell-admin::reports.demo_install_health_settings_rows_remediation'),
            id: 'admin.settings.rows',
            severity: DoctorCheckSeverity::Warning,
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

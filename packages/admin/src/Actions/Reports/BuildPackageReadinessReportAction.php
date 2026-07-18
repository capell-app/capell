<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Reports;

use Capell\Admin\Contracts\Reports\BuildsReportSnapshot;
use Capell\Admin\Data\Reports\ReportFindingData;
use Capell\Admin\Data\Reports\ReportMetricData;
use Capell\Admin\Data\Reports\ReportSnapshotData;
use Capell\Admin\Enums\Reports\ReportFindingSeverity;
use Capell\Core\Actions\Packages\BuildPackageCapabilityGraphAction;
use Capell\Core\Contracts\SettingsContract;
use Capell\Core\Data\Diagnostics\PackageReadinessCheckData;
use Capell\Core\Data\Diagnostics\PackageReadinessPackageData;
use Capell\Core\Data\PackageCapabilityGraphData;
use Capell\Core\Data\PackageData;
use Capell\Core\Enums\PackageCapability;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\PublicRenderContractEvent;
use Capell\Core\Support\Database\RuntimeSchemaState;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Capell\Core\Support\Settings\SettingsSchemaRegistry;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

final class BuildPackageReadinessReportAction implements BuildsReportSnapshot
{
    use AsFake;
    use AsObject;

    private const int FINDING_LIMIT = 75;

    public function __construct(
        private readonly CapellPackageRegistry $packageRegistry,
        private readonly SettingsSchemaRegistry $settingsRegistry,
        private readonly RuntimeSchemaState $schemaState,
    ) {}

    public function handle(): ReportSnapshotData
    {
        $manifests = $this->packageRegistry->all();
        $capabilityGraph = BuildPackageCapabilityGraphAction::run($manifests);
        $packages = array_values(array_map(
            fn (CapellManifestData $manifest): PackageReadinessPackageData => $this->packageReadiness($manifest, $capabilityGraph),
            $manifests,
        ));

        $criticalCount = array_sum(array_map(
            fn (PackageReadinessPackageData $package): int => $package->criticalCount(),
            $packages,
        ));
        $warningCount = array_sum(array_map(
            fn (PackageReadinessPackageData $package): int => $package->warningCount(),
            $packages,
        ));
        $readyCount = count(array_filter(
            $packages,
            fn (PackageReadinessPackageData $package): bool => $package->ready(),
        ));

        return new ReportSnapshotData(
            key: 'core.package_readiness',
            emptyState: __('capell-admin::reports.empty_state_package_readiness'),
            metrics: [
                new ReportMetricData(
                    label: __('capell-admin::reports.package_readiness_metric_packages_checked'),
                    value: count($packages),
                    description: __('capell-admin::reports.package_readiness_metric_packages_checked_description'),
                ),
                new ReportMetricData(
                    label: __('capell-admin::reports.package_readiness_metric_ready_packages'),
                    value: $readyCount,
                    description: __('capell-admin::reports.package_readiness_metric_ready_packages_description'),
                ),
                new ReportMetricData(
                    label: __('capell-admin::reports.package_readiness_metric_warnings'),
                    value: $warningCount,
                    description: __('capell-admin::reports.package_readiness_metric_warnings_description'),
                ),
                new ReportMetricData(
                    label: __('capell-admin::reports.package_readiness_metric_critical'),
                    value: $criticalCount,
                    description: __('capell-admin::reports.package_readiness_metric_critical_description'),
                ),
            ],
            findings: $this->findings($packages),
        );
    }

    private function packageReadiness(CapellManifestData $manifest, PackageCapabilityGraphData $capabilityGraph): PackageReadinessPackageData
    {
        return new PackageReadinessPackageData(
            packageName: $manifest->name,
            label: $this->package($manifest->name)?->getLabel() ?? $manifest->displayName,
            checks: [
                $this->manifestCheck($manifest),
                $this->migrationCheck($manifest),
                $this->frontendAssetsCheck($manifest, $capabilityGraph),
                $this->settingsCheck($manifest),
                $this->marketplaceMetadataCheck($manifest),
                $this->publicRenderSafetyCheck($manifest, $capabilityGraph),
            ],
        );
    }

    private function manifestCheck(CapellManifestData $manifest): PackageReadinessCheckData
    {
        $missing = array_values(array_filter([
            $manifest->name !== '' ? null : 'name',
            $manifest->displayName !== '' ? null : 'displayName',
            $manifest->kind !== '' ? null : 'kind',
            $manifest->version !== '' ? null : 'version',
        ]));

        if ($missing !== []) {
            return new PackageReadinessCheckData(
                key: 'manifest',
                label: __('capell-admin::reports.package_readiness_check_manifest'),
                passed: false,
                severity: 'critical',
                message: __('capell-admin::reports.package_readiness_manifest_missing', ['fields' => implode(', ', $missing)]),
            );
        }

        return new PackageReadinessCheckData(
            key: 'manifest',
            label: __('capell-admin::reports.package_readiness_check_manifest'),
            passed: true,
            message: __('capell-admin::reports.package_readiness_manifest_passed'),
        );
    }

    private function migrationCheck(CapellManifestData $manifest): PackageReadinessCheckData
    {
        $database = $manifest->database;
        $requiresMigrations = ($database['migrations'] ?? false) === true;
        $requiredTables = $this->stringList($database['requiredTables'] ?? []);

        if ($requiredTables === []) {
            return new PackageReadinessCheckData(
                key: 'migrations',
                label: __('capell-admin::reports.package_readiness_check_migrations'),
                passed: ! $requiresMigrations,
                severity: 'warning',
                message: $requiresMigrations
                    ? __('capell-admin::reports.package_readiness_migrations_unverifiable')
                    : __('capell-admin::reports.package_readiness_migrations_not_required'),
            );
        }

        $missingTables = array_values(array_filter(
            $requiredTables,
            fn (string $table): bool => ! $this->schemaState->hasTable($table),
        ));

        if ($missingTables !== []) {
            return new PackageReadinessCheckData(
                key: 'migrations',
                label: __('capell-admin::reports.package_readiness_check_migrations'),
                passed: false,
                severity: 'critical',
                message: __('capell-admin::reports.package_readiness_migrations_missing_tables', ['tables' => implode(', ', $missingTables)]),
            );
        }

        return new PackageReadinessCheckData(
            key: 'migrations',
            label: __('capell-admin::reports.package_readiness_check_migrations'),
            passed: true,
            message: __('capell-admin::reports.package_readiness_migrations_passed'),
        );
    }

    private function frontendAssetsCheck(CapellManifestData $manifest, PackageCapabilityGraphData $capabilityGraph): PackageReadinessCheckData
    {
        if (! $capabilityGraph->packageHas($manifest->name, PackageCapability::FrontendSurface)) {
            return new PackageReadinessCheckData(
                key: 'frontend_assets',
                label: __('capell-admin::reports.package_readiness_check_frontend_assets'),
                passed: true,
                message: __('capell-admin::reports.package_readiness_frontend_assets_not_required'),
            );
        }

        if ($capabilityGraph->packageHas($manifest->name, PackageCapability::FrontendAssets)) {
            return new PackageReadinessCheckData(
                key: 'frontend_assets',
                label: __('capell-admin::reports.package_readiness_check_frontend_assets'),
                passed: true,
                message: __('capell-admin::reports.package_readiness_frontend_assets_passed'),
            );
        }

        return new PackageReadinessCheckData(
            key: 'frontend_assets',
            label: __('capell-admin::reports.package_readiness_check_frontend_assets'),
            passed: false,
            severity: 'warning',
            message: __('capell-admin::reports.package_readiness_frontend_assets_missing'),
        );
    }

    private function settingsCheck(CapellManifestData $manifest): PackageReadinessCheckData
    {
        if ($manifest->settings === []) {
            return new PackageReadinessCheckData(
                key: 'settings',
                label: __('capell-admin::reports.package_readiness_check_settings'),
                passed: true,
                message: __('capell-admin::reports.package_readiness_settings_not_required'),
            );
        }

        $missing = [];

        foreach ($manifest->settings as $settingsClass) {
            if (! class_exists($settingsClass) || ! is_a($settingsClass, SettingsContract::class, true)) {
                $missing[] = $settingsClass;

                continue;
            }

            $group = $settingsClass::group();

            if ($this->settingsRegistry->getSettingsClass($group) !== $settingsClass) {
                $missing[] = $settingsClass;
            }
        }

        if ($missing !== []) {
            return new PackageReadinessCheckData(
                key: 'settings',
                label: __('capell-admin::reports.package_readiness_check_settings'),
                passed: false,
                severity: 'critical',
                message: __('capell-admin::reports.package_readiness_settings_missing', ['classes' => implode(', ', $missing)]),
            );
        }

        return new PackageReadinessCheckData(
            key: 'settings',
            label: __('capell-admin::reports.package_readiness_check_settings'),
            passed: true,
            message: __('capell-admin::reports.package_readiness_settings_passed'),
        );
    }

    private function marketplaceMetadataCheck(CapellManifestData $manifest): PackageReadinessCheckData
    {
        $missing = array_values(array_filter([
            $manifest->description !== null ? null : 'description',
            $manifest->commercial->supportPolicy !== null ? null : 'commercial.supportPolicy',
            $manifest->marketplaceHidden || $manifest->marketplaceSummary !== null ? null : 'marketplace.summary',
        ]));

        if ($missing !== []) {
            return new PackageReadinessCheckData(
                key: 'marketplace_metadata',
                label: __('capell-admin::reports.package_readiness_check_marketplace_metadata'),
                passed: false,
                severity: 'warning',
                message: __('capell-admin::reports.package_readiness_marketplace_missing', ['fields' => implode(', ', $missing)]),
            );
        }

        return new PackageReadinessCheckData(
            key: 'marketplace_metadata',
            label: __('capell-admin::reports.package_readiness_check_marketplace_metadata'),
            passed: true,
            message: __('capell-admin::reports.package_readiness_marketplace_passed'),
        );
    }

    private function publicRenderSafetyCheck(CapellManifestData $manifest, PackageCapabilityGraphData $capabilityGraph): PackageReadinessCheckData
    {
        if (! $capabilityGraph->packageHas($manifest->name, PackageCapability::FrontendSurface)) {
            return new PackageReadinessCheckData(
                key: 'public_render_safety',
                label: __('capell-admin::reports.package_readiness_check_public_render_safety'),
                passed: true,
                message: __('capell-admin::reports.package_readiness_public_render_not_required'),
            );
        }

        if (! $this->schemaState->hasTable('capell_public_render_contract_events')) {
            return new PackageReadinessCheckData(
                key: 'public_render_safety',
                label: __('capell-admin::reports.package_readiness_check_public_render_safety'),
                passed: false,
                severity: 'warning',
                message: __('capell-admin::reports.package_readiness_public_render_unavailable'),
            );
        }

        $latest = PublicRenderContractEvent::query()
            ->where('package_name', $manifest->name)
            ->latest('id')
            ->first();

        if ($latest === null) {
            return new PackageReadinessCheckData(
                key: 'public_render_safety',
                label: __('capell-admin::reports.package_readiness_check_public_render_safety'),
                passed: true,
                message: __('capell-admin::reports.package_readiness_public_render_unknown'),
            );
        }

        if ($latest->result === 'failed') {
            return new PackageReadinessCheckData(
                key: 'public_render_safety',
                label: __('capell-admin::reports.package_readiness_check_public_render_safety'),
                passed: false,
                severity: 'critical',
                message: __('capell-admin::reports.package_readiness_public_render_failed', ['matched' => (string) $latest->matched_marker]),
            );
        }

        return new PackageReadinessCheckData(
            key: 'public_render_safety',
            label: __('capell-admin::reports.package_readiness_check_public_render_safety'),
            passed: true,
            message: __('capell-admin::reports.package_readiness_public_render_passed'),
        );
    }

    /**
     * @param  array<int, PackageReadinessPackageData>  $packages
     * @return list<ReportFindingData>
     */
    private function findings(array $packages): array
    {
        $findings = [];

        foreach ($packages as $package) {
            foreach ($package->checks as $check) {
                if ($check->passed) {
                    continue;
                }

                $findings[] = new ReportFindingData(
                    severity: $check->severity === 'critical' ? ReportFindingSeverity::Critical : ReportFindingSeverity::Warning,
                    title: sprintf('%s: %s', $package->label, $check->label),
                    description: $check->message,
                    recordLabel: $package->packageName,
                );

                if (count($findings) >= self::FINDING_LIMIT) {
                    return $findings;
                }
            }
        }

        return $findings;
    }

    private function package(string $packageName): ?PackageData
    {
        try {
            return CapellCore::getPackage($packageName);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, is_string(...)));
    }
}

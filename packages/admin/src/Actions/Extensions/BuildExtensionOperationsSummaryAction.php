<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Extensions;

use Capell\Admin\Data\Extensions\ExtensionHealthAlertData;
use Capell\Admin\Data\Extensions\ExtensionOperationPackageData;
use Capell\Admin\Data\Extensions\ExtensionOperationsSummaryData;
use Capell\Admin\Support\Extensions\ComposerDriftClassifier;
use Capell\Admin\Support\Extensions\ComposerDriftMetadata;
use Capell\Admin\Support\Extensions\ExtensionOperationsRequestCache;
use Capell\Core\Actions\ResolveExtensionRuntimeGateAction;
use Capell\Core\Data\ExtensionRuntimeGateData;
use Capell\Core\Data\Manifest\ExtensionContributionData;
use Capell\Core\Data\Manifest\ExtensionScreenshotData;
use Capell\Core\Data\PackageData;
use Capell\Core\Enums\ExtensionHealthAlertCategory;
use Capell\Core\Enums\ExtensionHealthAlertSeverity;
use Capell\Core\Enums\ExtensionStatusEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\CapellExtension;
use Capell\Core\Models\ExtensionHealthAlert;
use Capell\Core\Support\Database\RuntimeSchemaState;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\Marketplace\MarketplaceAssetUrl;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

final class BuildExtensionOperationsSummaryAction
{
    use AsAction;

    private const string REQUEST_CACHE_KEY = 'extension-operations-summary';

    /** @var array<string, bool> */
    private array $tableExistsCache = [];

    /** @var array<string, true>|null */
    private ?array $installedDependencyPackageNames = null;

    public static function forgetRequestCache(): void
    {
        resolve(ExtensionOperationsRequestCache::class)->forget(self::REQUEST_CACHE_KEY);
    }

    public function handle(): ExtensionOperationsSummaryData
    {
        return resolve(ExtensionOperationsRequestCache::class)->remember(
            self::REQUEST_CACHE_KEY,
            fn (): ExtensionOperationsSummaryData => $this->build(),
        );
    }

    private function build(): ExtensionOperationsSummaryData
    {
        $registry = resolve(CapellPackageRegistry::class);
        $extensions = $this->extensions();
        $alerts = $this->healthAlerts();
        $marketplaceAccountConnected = $this->marketplaceAccountConnected();

        $packages = collect($registry->all())
            ->mapWithKeys(fn (CapellManifestData $manifest, string $packageName): array => [
                $packageName => $this->packageSummary(
                    manifest: $manifest,
                    package: $this->package($packageName),
                    extension: $extensions->get($packageName),
                    alerts: $alerts->get($packageName, collect()),
                    marketplaceAccountConnected: $marketplaceAccountConnected,
                    contributions: $registry->contributionsForPackage($packageName),
                ),
            ]);

        $driftedExtensions = $this->composerDriftedExtensions($extensions);

        foreach ($driftedExtensions as $extension) {
            if ($packages->has($extension->composer_name)) {
                continue;
            }

            $packages->put($extension->composer_name, $this->driftedPackageSummary($extension));
        }

        $alertRecords = array_values($packages
            ->flatMap(fn (ExtensionOperationPackageData $package): array => $package->healthAlerts)
            ->values()
            ->all());

        return new ExtensionOperationsSummaryData(
            needsAttentionCount: $packages->filter(fn (ExtensionOperationPackageData $package): bool => $package->needsAttention)->count(),
            blockedCount: $packages->filter(fn (ExtensionOperationPackageData $package): bool => $package->blocked)->count(),
            updatesCount: $packages->filter(fn (ExtensionOperationPackageData $package): bool => $package->updateAvailable)->count(),
            unhealthyCount: $packages->filter(fn (ExtensionOperationPackageData $package): bool => $package->healthState !== 'ok')->count(),
            installedCount: $packages->filter(fn (ExtensionOperationPackageData $package): bool => $package->installed)->count(),
            uninstalledCount: $packages->reject(fn (ExtensionOperationPackageData $package): bool => $package->installed)->count(),
            availableCount: $packages->filter(fn (ExtensionOperationPackageData $package): bool => $package->available)->count(),
            packages: $packages->all(),
            alerts: $alertRecords,
        );
    }

    /**
     * @param  Collection<int, ExtensionHealthAlert>  $alerts
     * @param  list<ExtensionContributionData>  $contributions
     */
    private function packageSummary(
        CapellManifestData $manifest,
        ?PackageData $package,
        ?CapellExtension $extension,
        Collection $alerts,
        bool $marketplaceAccountConnected,
        array $contributions,
    ): ExtensionOperationPackageData {
        $tier = $this->normaliseState($package?->getTier() ?? $manifest->tier, 'free');
        $requestedCertificationStatus = $package instanceof PackageData
            ? $package->requestedCertificationStatus
            : null;
        $certification = $this->normaliseState(
            $this->metadataString($extension, 'certification_status')
                ?? $requestedCertificationStatus
                ?? $manifest->commercial->requestedCertification,
            'community',
        );
        $latestVersion = $this->metadataString($extension, 'latest_version')
            ?? $this->metadataString($extension, 'recommended_version');
        $packageVersion = $package instanceof PackageData ? $package->version : null;
        $extensionVersion = $extension instanceof CapellExtension ? $extension->version : null;
        $currentVersion = $extensionVersion ?? $packageVersion ?? $manifest->version;
        $updateAvailable = $this->updateAvailable($currentVersion, $latestVersion);
        $missingRequiredTables = $this->missingRequiredTables($manifest);
        $runtimeGate = $extension instanceof CapellExtension
            ? ResolveExtensionRuntimeGateAction::run($extension)
            : null;
        $runtimeAllowed = $runtimeGate instanceof ExtensionRuntimeGateData
            ? $runtimeGate->allowed
            : true;
        $runtimeStatus = $this->runtimeStatus($extension, $runtimeGate?->reason);
        $managementEntries = $this->managementEntries($contributions);
        $healthAlerts = $this->healthAlertRecords($alerts, $managementEntries);
        $healthState = $this->healthState($alerts, $missingRequiredTables, $runtimeAllowed);
        $installed = $extension instanceof CapellExtension || ($package?->isInstalled() === true);
        $available = $package instanceof PackageData && CapellCore::isPackageAvailable($package->name);
        $composerDriftAlert = $this->composerDriftAlert($manifest->name, $extension);

        if ($composerDriftAlert instanceof ExtensionHealthAlertData) {
            $healthAlerts[] = $composerDriftAlert;
            $healthState = $healthState === 'critical' ? 'critical' : 'warning';
        }

        $premiumMissingMarketplaceAccount = $tier === 'premium'
            && $installed
            && ! $marketplaceAccountConnected
            && $extension?->marketplace_runtime_status !== 'active';
        $blocked = ! $runtimeAllowed;
        $needsAttention = $blocked || $updateAvailable || $missingRequiredTables !== [] || $premiumMissingMarketplaceAccount || $healthState !== 'ok' || $composerDriftAlert instanceof ExtensionHealthAlertData;
        $riskScore = BuildExtensionRiskScoreAction::run(
            runtimeAllowed: $runtimeAllowed,
            alerts: $healthAlerts,
            missingRequiredTables: $missingRequiredTables,
            premiumMissingMarketplaceAccount: $premiumMissingMarketplaceAccount,
            updateAvailable: $updateAvailable,
            blocked: $blocked,
            canUninstall: $package instanceof PackageData && $this->canUninstallPackage($package),
        );

        return new ExtensionOperationPackageData(
            packageName: $manifest->name,
            label: ($extension instanceof CapellExtension ? $extension->name : null) ?? ($package instanceof PackageData ? $package->getShortName() : null) ?? $manifest->displayName,
            description: ($extension instanceof CapellExtension ? $extension->description : null) ?? ($package instanceof PackageData ? $package->getDescription() : null) ?? $manifest->description,
            version: $currentVersion,
            latestVersion: $latestVersion,
            imageUrl: $this->imageUrl($manifest, $package),
            imageUrls: $this->imageUrls($manifest, $package),
            updateAvailable: $updateAvailable,
            installed: $installed,
            enabled: $package instanceof PackageData && CapellCore::isPackageEnabled($package->name),
            available: $available,
            core: $package?->isCore() === true,
            visibility: $package->visibility ?? $manifest->visibility,
            canUninstall: $package instanceof PackageData && $this->canUninstallPackage($package),
            tier: $tier,
            certification: $certification,
            runtimeStatus: $runtimeStatus,
            runtimeAllowed: $runtimeAllowed,
            healthState: $healthState,
            healthAlerts: $healthAlerts,
            missingRequiredTables: $missingRequiredTables,
            contributionCount: count($contributions),
            managementEntries: $managementEntries,
            premiumMissingMarketplaceAccount: $premiumMissingMarketplaceAccount,
            blocked: $blocked,
            needsAttention: $needsAttention,
            riskScore: $riskScore,
            productGroup: $this->normaliseProductGroup($manifest->productGroup),
        );
    }

    private function driftedPackageSummary(CapellExtension $extension): ExtensionOperationPackageData
    {
        $alert = $this->composerDriftAlert($extension->composer_name, $extension);
        $alerts = $alert instanceof ExtensionHealthAlertData ? [$alert] : [];

        return new ExtensionOperationPackageData(
            packageName: $extension->composer_name,
            label: $extension->name ?? str($extension->composer_name)->afterLast('/')->replace('-', ' ')->title()->toString(),
            description: $extension->description,
            version: $extension->version,
            latestVersion: $this->metadataString($extension, 'latest_version')
                ?? $this->metadataString($extension, 'recommended_version'),
            imageUrl: null,
            imageUrls: [],
            updateAvailable: false,
            installed: true,
            enabled: $extension->status === ExtensionStatusEnum::Enabled,
            available: false,
            core: false,
            visibility: 'catalogue',
            canUninstall: false,
            tier: $this->normaliseState($this->metadataString($extension, 'tier'), 'free'),
            certification: $this->normaliseState($this->metadataString($extension, 'certification_status'), 'community'),
            runtimeStatus: 'composer_missing',
            runtimeAllowed: false,
            healthState: 'warning',
            healthAlerts: $alerts,
            missingRequiredTables: [],
            contributionCount: 0,
            managementEntries: [],
            premiumMissingMarketplaceAccount: false,
            blocked: true,
            needsAttention: true,
            riskScore: BuildExtensionRiskScoreAction::run(
                runtimeAllowed: false,
                alerts: $alerts,
                missingRequiredTables: [],
                premiumMissingMarketplaceAccount: false,
                updateAvailable: false,
                blocked: true,
                canUninstall: false,
            ),
            productGroup: $this->normaliseProductGroup($this->metadataString($extension, 'product_group')),
        );
    }

    /** @return Collection<string, CapellExtension> */
    private function extensions(): Collection
    {
        if (! $this->tableExists('capell_extensions')) {
            return collect();
        }

        try {
            /** @var Collection<string, CapellExtension> $extensions */
            $extensions = CapellExtension::query()
                ->get()
                ->keyBy('composer_name');

            return $extensions;
        } catch (Throwable) {
            return collect();
        }
    }

    /** @return Collection<string, Collection<int, ExtensionHealthAlert>> */
    private function healthAlerts(): Collection
    {
        if (! $this->tableExists('capell_extension_health_alerts')) {
            return collect();
        }

        try {
            /** @var Collection<string, Collection<int, ExtensionHealthAlert>> $alerts */
            $alerts = ExtensionHealthAlert::query()
                ->where(fn (Builder $query) => $query
                    ->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now()))
                ->get()
                ->groupBy('composer_name');

            return $alerts;
        } catch (Throwable) {
            return collect();
        }
    }

    private function package(string $packageName): ?PackageData
    {
        return CapellCore::hasPackage($packageName)
            ? CapellCore::getPackage($packageName)
            : null;
    }

    /**
     * @param  list<ExtensionContributionData>  $contributions
     * @return list<array{label: string, url: string, permission: ?string, type: string}>
     */
    private function managementEntries(array $contributions): array
    {
        return array_values(collect($contributions)
            ->map(fn (ExtensionContributionData $contribution): ?array => $this->managementEntry($contribution))
            ->filter()
            ->values()
            ->all());
    }

    /**
     * @return array{label: string, url: string, permission: ?string, type: string}|null
     */
    private function managementEntry(ExtensionContributionData $contribution): ?array
    {
        $permission = $contribution->metadata['permission'] ?? null;

        if (is_string($permission) && $permission !== '' && auth()->user()?->can($permission) !== true) {
            return null;
        }

        $url = $this->managementUrl($contribution);

        if ($url === null) {
            return null;
        }

        $label = $this->translatedMetadata($contribution, 'labelKey')
            ?? $contribution->metadata['label']
            ?? null;

        return [
            'label' => is_string($label) && $label !== ''
                ? $label
                : str($contribution->type->value)->replace('-', ' ')->title()->toString(),
            'url' => $url,
            'permission' => is_string($permission) && $permission !== '' ? $permission : null,
            'type' => $contribution->type->value,
        ];
    }

    private function managementUrl(ExtensionContributionData $contribution): ?string
    {
        $pageClass = $contribution->metadata['pageClass'] ?? null;

        if (is_string($pageClass) && class_exists($pageClass) && method_exists($pageClass, 'getUrl')) {
            if (method_exists($pageClass, 'canAccess') && $pageClass::canAccess() !== true) {
                return null;
            }

            if (method_exists($pageClass, 'getRouteName') && ! Route::has($pageClass::getRouteName())) {
                return null;
            }

            $parameters = $this->stringArray($contribution->metadata['pageParameters'] ?? []);

            /** @var callable(array<string, string>): string $getUrl */
            $getUrl = [$pageClass, 'getUrl'];

            try {
                return $getUrl($parameters);
            } catch (Throwable) {
                return null;
            }
        }

        $route = $contribution->metadata['managementRoute'] ?? null;

        if (! is_string($route) || $route === '' || ! Route::has($route)) {
            return null;
        }

        try {
            return route($route, $this->stringArray($contribution->metadata['routeParameters'] ?? []));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, string>
     */
    private function stringArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->filter(fn (mixed $item, mixed $key): bool => is_string($key) && (is_string($item) || is_numeric($item)))
            ->map(fn (mixed $item): string => (string) $item)
            ->all();
    }

    private function translatedMetadata(ExtensionContributionData $contribution, string $key): ?string
    {
        $translation = $contribution->metadata[$key] ?? null;

        return is_string($translation) && $translation !== '' ? (string) __($translation) : null;
    }

    private function imageUrl(CapellManifestData $manifest, ?PackageData $package): ?string
    {
        return $this->imageUrls($manifest, $package)[0] ?? null;
    }

    /** @return list<string> */
    private function imageUrls(CapellManifestData $manifest, ?PackageData $package): array
    {
        $packagePreviewImageUrl = $package?->getPreviewImageUrl();
        $imageUrls = [];

        if (is_string($packagePreviewImageUrl) && $packagePreviewImageUrl !== '') {
            $imageUrls[] = $packagePreviewImageUrl;
        }

        $screenshotImageUrls = collect($manifest->marketplaceScreenshots)
            ->map(fn (ExtensionScreenshotData $screenshot): ?string => $this->screenshotImageUrl($manifest, $screenshot->path))
            ->filter();

        return array_values(collect($imageUrls)
            ->concat($screenshotImageUrls)
            ->unique()
            ->values()
            ->all());
    }

    private function screenshotImageUrl(CapellManifestData $manifest, string $path): ?string
    {
        if ($path === '') {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://') || str_starts_with($path, 'data:image/')) {
            return $path;
        }

        if ($manifest->installPath !== null && Route::has('capell-admin.extension-asset')) {
            $packagePath = realpath($manifest->installPath);
            $assetPath = realpath($manifest->installPath . DIRECTORY_SEPARATOR . ltrim($path, '/'));

            if (
                is_string($packagePath)
                && is_string($assetPath)
                && str_starts_with($assetPath, $packagePath . DIRECTORY_SEPARATOR)
                && is_file($assetPath)
            ) {
                return route('capell-admin.extension-asset', [
                    'package' => $manifest->name,
                    'path' => ltrim($path, '/'),
                ]);
            }
        }

        return $this->marketplaceAssetUrl($path);
    }

    private function marketplaceAssetUrl(string $path): ?string
    {
        return MarketplaceAssetUrl::toUrl($path);
    }

    /**
     * @param  Collection<int, ExtensionHealthAlert>  $alerts
     * @param  list<array{label: string, url: string, permission: ?string, type: string}>  $managementEntries
     * @return list<ExtensionHealthAlertData>
     */
    private function healthAlertRecords(Collection $alerts, array $managementEntries): array
    {
        $primaryManagementEntry = $managementEntries[0] ?? null;

        return array_values($alerts
            ->map(fn (ExtensionHealthAlert $alert): ExtensionHealthAlertData => new ExtensionHealthAlertData(
                id: $alert->alert_id,
                packageName: $alert->composer_name ?? '',
                severity: $alert->severity->value,
                category: $alert->category->value,
                title: $alert->title,
                message: $alert->message,
                requiredAction: $alert->required_action,
                runtimeDisabled: $alert->runtime_disabled,
                protectedActionsBlocked: $alert->protected_actions_blocked,
                managementUrl: $primaryManagementEntry['url'] ?? null,
                managementLabel: $primaryManagementEntry['label'] ?? null,
            ))
            ->values()
            ->all());
    }

    /**
     * @return list<string>
     */
    private function missingRequiredTables(CapellManifestData $manifest): array
    {
        $requiredTables = $manifest->database['requiredTables'] ?? [];

        if (! is_array($requiredTables)) {
            return [];
        }

        return array_values(collect($requiredTables)
            ->filter(fn (mixed $table): bool => is_string($table) && $table !== '')
            ->reject(fn (string $table): bool => $this->tableExists($table))
            ->values()
            ->all());
    }

    /**
     * @param  Collection<int, ExtensionHealthAlert>  $alerts
     * @param  list<string>  $missingRequiredTables
     */
    private function healthState(Collection $alerts, array $missingRequiredTables, bool $runtimeAllowed): string
    {
        if ($alerts->contains(fn (ExtensionHealthAlert $alert): bool => $alert->severity === ExtensionHealthAlertSeverity::Critical) || ! $runtimeAllowed) {
            return 'critical';
        }

        if ($alerts->contains(fn (ExtensionHealthAlert $alert): bool => $alert->severity === ExtensionHealthAlertSeverity::Warning) || $missingRequiredTables !== []) {
            return 'warning';
        }

        if ($alerts->isNotEmpty()) {
            return 'info';
        }

        return 'ok';
    }

    private function runtimeStatus(?CapellExtension $extension, ?string $runtimeReason): string
    {
        if (! $extension instanceof CapellExtension) {
            return 'not_installed';
        }

        if (is_string($extension->marketplace_runtime_status) && $extension->marketplace_runtime_status !== '') {
            return $extension->marketplace_runtime_status;
        }

        if ($runtimeReason !== null && $runtimeReason !== '') {
            return $runtimeReason;
        }

        return $extension->status->value;
    }

    private function marketplaceAccountConnected(): bool
    {
        if (! $this->tableExists('marketplace_instances')) {
            return false;
        }

        try {
            return resolve(RuntimeSchemaState::class)->hasColumn('marketplace_instances', 'connected_at')
                ? resolve(ConnectionResolverInterface::class)->table('marketplace_instances')->whereNotNull('connected_at')->exists()
                : resolve(ConnectionResolverInterface::class)->table('marketplace_instances')->exists();
        } catch (Throwable) {
            return false;
        }
    }

    private function updateAvailable(?string $currentVersion, ?string $latestVersion): bool
    {
        if ($currentVersion === null || $latestVersion === null || $latestVersion === '') {
            return false;
        }

        return version_compare(ltrim($latestVersion, 'v'), ltrim($currentVersion, 'v'), '>');
    }

    private function metadataString(?CapellExtension $extension, string $key): ?string
    {
        $value = $extension?->metadata[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function normaliseState(?string $value, string $fallback): string
    {
        if ($value === null || trim($value) === '') {
            return $fallback;
        }

        return str($value)->lower()->replace(' ', '-')->toString();
    }

    private function normaliseProductGroup(mixed $value): string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : 'Other';
    }

    /**
     * @param  Collection<string, CapellExtension>  $extensions
     * @return Collection<int, CapellExtension>
     */
    private function composerDriftedExtensions(Collection $extensions): Collection
    {
        return $extensions
            ->filter(fn (CapellExtension $extension): bool => resolve(ComposerDriftClassifier::class)->reason($extension) !== null)
            ->values();
    }

    private function composerDriftAlert(string $packageName, ?CapellExtension $extension): ?ExtensionHealthAlertData
    {
        if (! $extension instanceof CapellExtension) {
            return null;
        }

        $reason = resolve(ComposerDriftClassifier::class)->reason($extension);

        if ($reason === null) {
            return null;
        }

        $lastRepairAttempt = ComposerDriftMetadata::lastRepairAttempt($extension);
        $message = (string) __('capell-admin::dashboard.extension_composer_drift_message', [
            'package' => $packageName,
            'reason' => (string) __('capell-admin::dashboard.extension_composer_drift_reason_' . $reason),
        ]);

        if ($lastRepairAttempt !== null) {
            $message .= ' ' . __('capell-admin::dashboard.extension_composer_drift_last_repair_attempt', $lastRepairAttempt);
        }

        return new ExtensionHealthAlertData(
            id: 'composer_drift_' . hash('sha256', $packageName),
            packageName: $packageName,
            severity: ExtensionHealthAlertSeverity::Warning->value,
            category: ExtensionHealthAlertCategory::Package->value,
            title: (string) __('capell-admin::dashboard.extension_composer_drift_title'),
            message: $message,
            requiredAction: (string) __('capell-admin::dashboard.extension_composer_drift_required_action'),
            runtimeDisabled: true,
            protectedActionsBlocked: true,
        );
    }

    private function tableExists(string $table): bool
    {
        if (array_key_exists($table, $this->tableExistsCache)) {
            return $this->tableExistsCache[$table];
        }

        try {
            return $this->tableExistsCache[$table] = resolve(RuntimeSchemaState::class)->hasTable($table);
        } catch (Throwable) {
            return $this->tableExistsCache[$table] = false;
        }
    }

    private function canUninstallPackage(PackageData $package): bool
    {
        if (! CapellCore::hasPackage($package->name)) {
            return false;
        }

        return ! isset($this->installedDependencyPackageNames()[$package->name]);
    }

    /** @return array<string, true> */
    private function installedDependencyPackageNames(): array
    {
        if ($this->installedDependencyPackageNames !== null) {
            return $this->installedDependencyPackageNames;
        }

        $dependencyPackageNames = [];

        foreach (CapellCore::getInstalledPackages() as $installedPackage) {
            foreach ($installedPackage->getRequirements() as $requiredPackageName) {
                $dependencyPackageNames[$requiredPackageName] = true;
            }
        }

        return $this->installedDependencyPackageNames = $dependencyPackageNames;
    }
}

<?php

declare(strict_types=1);

namespace Capell\Admin\Actions;

use BackedEnum;
use Capell\Admin\Actions\Extensions\BuildExtensionOperationsSummaryAction;
use Capell\Admin\Actions\Extensions\ListExtensionOperationPackagesAction;
use Capell\Admin\Data\ExtensionManagementEntryData;
use Capell\Admin\Data\Extensions\ExtensionHealthAlertData;
use Capell\Admin\Data\Extensions\ExtensionManagementSurfaceData;
use Capell\Admin\Data\Extensions\ExtensionOperationPackageData;
use Capell\Admin\Data\Extensions\ExtensionOperationsSummaryData;
use Capell\Admin\Support\Extensions\ExtensionManagementSurfaceRegistry;
use Capell\Admin\Support\Extensions\ExtensionOperationsRequestCache;
use Capell\Admin\Support\Extensions\ExtensionPageRegistry;
use Capell\Core\Data\PackageData;
use Capell\Core\Enums\ExtensionStatusEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\CapellExtension;
use Capell\Core\Support\Database\RuntimeSchemaState;
use Capell\Core\Support\Marketplace\MarketplaceAssetUrl;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

final class ListExtensionManagementEntriesAction
{
    use AsAction;

    private const string REQUEST_CACHE_KEY = 'extension-management-entries';

    /** @var Collection<string, CapellExtension>|null */
    private ?Collection $extensions = null;

    public static function forgetRequestCache(): void
    {
        resolve(ExtensionOperationsRequestCache::class)->forget(self::REQUEST_CACHE_KEY);
    }

    /**
     * @return list<ExtensionManagementEntryData>
     */
    public function handle(): array
    {
        /** @var list<ExtensionManagementEntryData> $entries */
        $entries = resolve(ExtensionOperationsRequestCache::class)->remember(
            self::REQUEST_CACHE_KEY,
            fn (): array => $this->build(),
        );

        return $entries;
    }

    /**
     * @return list<ExtensionManagementEntryData>
     */
    private function build(): array
    {
        $summary = BuildExtensionOperationsSummaryAction::run();
        /** @var list<array{packageName: string, page: class-string<Page>}> $extensionPageEntries */
        $extensionPageEntries = resolve(ExtensionPageRegistry::class)->entries();
        /** @var Collection<int, array{packageName: string, page: class-string<Page>}> $registryEntries */
        $registryEntries = collect($extensionPageEntries);

        $pageEntries = $registryEntries
            ->groupBy('packageName')
            ->map(fn (Collection $entries): array => array_values($entries->pluck('page')->all()));
        $surfacePackageNames = $this->surfacePackageNames();

        /** @var list<ExtensionOperationPackageData> $operationPackages */
        $operationPackages = ListExtensionOperationPackagesAction::run();
        $operationPackageNames = collect($operationPackages)
            ->filter(fn (ExtensionOperationPackageData $package): bool => $this->shouldListOperationsPackage($package))
            ->map(fn (ExtensionOperationPackageData $package): string => $package->packageName)
            ->all();

        return array_values($pageEntries
            ->keys()
            ->merge($surfacePackageNames)
            ->merge($operationPackageNames)
            ->filter(fn (mixed $packageName): bool => is_string($packageName))
            ->unique()
            ->map(fn (string $packageName): ?ExtensionManagementEntryData => $this->entryForPackage(
                $packageName,
                $pageEntries->get($packageName, []),
                $summary,
            ))
            ->filter()
            ->sort(fn (ExtensionManagementEntryData $first, ExtensionManagementEntryData $second): int => $this->sortEntries($first, $second))
            ->values()
            ->all());
    }

    /**
     * @param  list<class-string<Page>>  $pages
     */
    private function entryForPackage(
        string $packageName,
        array $pages,
        ExtensionOperationsSummaryData $summary,
    ): ?ExtensionManagementEntryData {
        $accessiblePages = collect($pages)
            ->filter(fn (string $page): bool => $this->pageIsAccessible($page))
            ->values();
        $operations = $summary->package($packageName);
        $package = $this->package($packageName);
        $extension = $this->extension($packageName);

        if ($this->isHiddenFromExtensionManagement($package, $operations)) {
            return null;
        }

        $manifestManagementEntries = $this->manifestManagementEntries($operations);
        $managementSurfaces = $this->managementSurfaces($packageName);

        if ($accessiblePages->isEmpty() && $manifestManagementEntries === [] && $managementSurfaces === [] && ! ($operations instanceof ExtensionOperationPackageData && $this->shouldListOperationsPackage($operations))) {
            return null;
        }

        /** @var class-string<Page>|null $primaryPage */
        $primaryPage = $accessiblePages->first();
        $primaryUrl = $primaryPage !== null
            ? $this->pageUrl($primaryPage)
            : ($manifestManagementEntries[0]['url'] ?? null);
        $operations ??= $this->fallbackOperationsPackage($packageName, $package, $extension);

        return new ExtensionManagementEntryData(
            packageName: $packageName,
            label: $this->entryLabel($packageName, $package, $extension, $primaryPage, $operations),
            description: $this->stringValue($extension?->description) ?? $operations->description ?? $package?->getDescription(),
            version: $this->stringValue($extension?->version) ?? $operations->version ?? $package?->version,
            installed: $operations->installed,
            enabled: $operations->enabled,
            available: $operations->available,
            core: $operations->core,
            canUninstall: $operations->canUninstall,
            installedAt: $extension?->installed_at,
            updatedAt: CarbonImmutable::make($extension?->updated_at),
            primaryUrl: $primaryUrl,
            externalUrl: $this->marketplaceExtensionUrl($packageName) ?? $package?->getUrl(),
            documentationUrl: $package?->getDocumentationUrl(),
            authorName: $package?->author,
            imageUrl: $operations->imageUrl,
            icon: ($primaryPage !== null ? $this->pageIcon($primaryPage) : null) ?? $package?->getIcon(),
            imageUrls: $operations->imageUrls,
            secondaryPages: array_values($accessiblePages
                ->skip($primaryPage !== null ? 1 : 0)
                ->map(fn (string $page): ?array => $this->secondaryPageRecord($page))
                ->filter()
                ->concat($primaryPage === null ? $manifestManagementEntries : $this->secondaryManifestManagementEntries($manifestManagementEntries, $primaryUrl))
                ->values()
                ->all()),
            managementSurfaces: $managementSurfaces,
            latestVersion: $operations->latestVersion,
            updateAvailable: $operations->updateAvailable,
            tier: $operations->tier,
            certification: $operations->certification,
            runtimeStatus: $operations->runtimeStatus,
            runtimeAllowed: $operations->runtimeAllowed,
            healthState: $operations->healthState,
            contributionCount: $operations->contributionCount,
            healthAlerts: array_map(fn (ExtensionHealthAlertData $alert): array => $alert->toRecord(), $operations->healthAlerts),
            missingRequiredTables: $operations->missingRequiredTables,
            premiumMissingMarketplaceAccount: $operations->premiumMissingMarketplaceAccount,
            blocked: $operations->blocked,
            needsAttention: $operations->needsAttention,
            riskScore: $operations->riskScore,
            productGroup: $operations->productGroup,
        );
    }

    private function fallbackOperationsPackage(
        string $packageName,
        ?PackageData $package,
        ?CapellExtension $extension,
    ): ExtensionOperationPackageData {
        $installed = $package?->isInstalled() ?? $extension instanceof CapellExtension;

        return new ExtensionOperationPackageData(
            packageName: $packageName,
            label: $this->stringValue($extension?->name) ?? $package?->getLabel() ?? str($packageName)->afterLast('/')->replace('-', ' ')->title()->toString(),
            description: $this->stringValue($extension?->description) ?? $package?->getDescription(),
            version: $this->stringValue($extension?->version) ?? $package?->version,
            latestVersion: null,
            imageUrl: null,
            imageUrls: [],
            updateAvailable: false,
            installed: $installed,
            enabled: $extension?->status === ExtensionStatusEnum::Enabled || (! $extension instanceof CapellExtension && $installed),
            available: $package instanceof PackageData,
            core: $package?->isCore() ?? false,
            visibility: 'public',
            canUninstall: $package instanceof PackageData && CapellCore::canUninstallPackage($packageName),
            tier: 'free',
            certification: 'community',
            runtimeStatus: $installed ? 'installed' : 'not_installed',
            runtimeAllowed: true,
            healthState: 'ok',
            healthAlerts: [],
            missingRequiredTables: [],
            contributionCount: 0,
            managementEntries: [],
            premiumMissingMarketplaceAccount: false,
            blocked: false,
            needsAttention: false,
            riskScore: 0,
        );
    }

    private function sortEntries(ExtensionManagementEntryData $first, ExtensionManagementEntryData $second): int
    {
        $firstTimestamp = $this->lastInstalledOrUpdatedAt($first)?->getTimestamp() ?? PHP_INT_MIN;
        $secondTimestamp = $this->lastInstalledOrUpdatedAt($second)?->getTimestamp() ?? PHP_INT_MIN;

        if ($firstTimestamp !== $secondTimestamp) {
            return $secondTimestamp <=> $firstTimestamp;
        }

        return [strtolower($first->label), strtolower($first->packageName)]
            <=> [strtolower($second->label), strtolower($second->packageName)];
    }

    private function lastInstalledOrUpdatedAt(ExtensionManagementEntryData $entry): ?CarbonImmutable
    {
        if ($entry->updatedAt instanceof CarbonImmutable && $entry->installedAt instanceof CarbonImmutable) {
            return $entry->updatedAt->greaterThan($entry->installedAt)
                ? $entry->updatedAt
                : $entry->installedAt;
        }

        return $entry->updatedAt ?? $entry->installedAt;
    }

    /**
     * @param  class-string<Page>  $page
     */
    private function pageIsAccessible(string $page): bool
    {
        if (! is_subclass_of($page, Page::class)) {
            return false;
        }

        try {
            return $page::canAccess();
        } catch (Throwable) {
            return false;
        }
    }

    private function package(string $packageName): ?PackageData
    {
        if (! CapellCore::hasPackage($packageName)) {
            return null;
        }

        return CapellCore::getPackage($packageName);
    }

    private function extension(string $packageName): ?CapellExtension
    {
        return $this->extensions()->get($packageName);
    }

    /**
     * @return Collection<string, CapellExtension>
     */
    private function extensions(): Collection
    {
        if ($this->extensions instanceof Collection) {
            return $this->extensions;
        }

        try {
            if (! resolve(RuntimeSchemaState::class)->hasTable('capell_extensions')) {
                return $this->extensions = collect();
            }

            /** @var Collection<string, CapellExtension> $extensions */
            $extensions = CapellExtension::query()
                ->get()
                ->keyBy('composer_name');

            return $this->extensions = $extensions;
        } catch (Throwable) {
            return $this->extensions = collect();
        }
    }

    /**
     * @param  class-string<Page>|null  $primaryPage
     */
    private function entryLabel(
        string $packageName,
        ?PackageData $package,
        ?CapellExtension $extension,
        ?string $primaryPage,
        ?ExtensionOperationPackageData $operations,
    ): string {
        return $this->stringValue($extension?->name)
            ?? $operations->label
            ?? $package?->getShortName()
            ?? ($primaryPage !== null ? $this->stringValue($primaryPage::getNavigationLabel()) : null)
            ?? str($packageName)->afterLast('/')->replace('-', ' ')->title()->toString();
    }

    /**
     * @param  class-string<Page>  $page
     * @return array{label: string, url: string, icon: null|string|BackedEnum}|null
     */
    private function secondaryPageRecord(string $page): ?array
    {
        $url = $this->pageUrl($page);

        if ($url === null) {
            return null;
        }

        return [
            'label' => $this->stringValue($page::getNavigationLabel()) ?? class_basename($page),
            'url' => $url,
            'icon' => $this->pageIcon($page),
        ];
    }

    /**
     * @param  class-string<Page>  $page
     */
    private function pageUrl(string $page): ?string
    {
        try {
            $url = $page::getUrl();
        } catch (Throwable) {
            return null;
        }

        return $this->stringValue($url);
    }

    /**
     * @param  class-string<Page>  $page
     */
    private function pageIcon(string $page): null|string|BackedEnum
    {
        try {
            $icon = $page::getNavigationIcon();
        } catch (Throwable) {
            return null;
        }

        return $icon instanceof BackedEnum || is_string($icon) ? $icon : null;
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function marketplaceExtensionUrl(string $packageName): ?string
    {
        $webUrl = MarketplaceAssetUrl::webUrl();

        if ($webUrl === null) {
            return null;
        }

        return $webUrl . '/extensions/' . rawurlencode(str($packageName)->afterLast('/')->toString());
    }

    private function shouldListOperationsPackage(ExtensionOperationPackageData $package): bool
    {
        if ($package->visibility === 'support') {
            return false;
        }

        return $package->contributionCount > 0
            || $package->managementEntries !== []
            || $package->needsAttention
            || $package->blocked
            || $package->updateAvailable
            || $package->tier === 'premium'
            || $package->installed
            || $package->available;
    }

    private function isHiddenFromExtensionManagement(?PackageData $package, ?ExtensionOperationPackageData $operations): bool
    {
        if ($package instanceof PackageData && ! $package->isVisibleInCatalogue()) {
            return true;
        }

        return $operations instanceof ExtensionOperationPackageData
            && $operations->visibility === 'support';
    }

    /**
     * @return list<array{label: string, url: string, icon: null|string|BackedEnum}>
     */
    private function manifestManagementEntries(?ExtensionOperationPackageData $operations): array
    {
        $entries = $operations?->managementEntries;

        if (! is_array($entries)) {
            return [];
        }

        return array_values(collect($entries)
            ->map(function (array $entry): ?array {
                $label = $this->stringValue($entry['label']);
                $url = $this->stringValue($entry['url']);

                if ($label === null || $url === null) {
                    return null;
                }

                return [
                    'label' => $label,
                    'url' => $url,
                    'icon' => null,
                ];
            })
            ->filter()
            ->values()
            ->all());
    }

    /**
     * @param  list<array{label: string, url: string, icon: null|string|BackedEnum}>  $entries
     * @return list<array{label: string, url: string, icon: null|string|BackedEnum}>
     */
    private function secondaryManifestManagementEntries(array $entries, ?string $primaryUrl): array
    {
        return array_values(collect($entries)
            ->reject(fn (array $entry): bool => $entry['url'] === $primaryUrl)
            ->values()
            ->all());
    }

    /**
     * @return list<ExtensionManagementSurfaceData>
     */
    private function managementSurfaces(string $packageName): array
    {
        $registeredSurfaces = resolve(ExtensionManagementSurfaceRegistry::class)
            ->surfacesForPackage($packageName);

        return array_values(collect($registeredSurfaces)
            ->unique(fn (ExtensionManagementSurfaceData $surface): string => $surface->type . ':' . ($surface->settingsGroup ?? ''))
            ->values()
            ->all());
    }

    /**
     * @return list<string>
     */
    private function surfacePackageNames(): array
    {
        return resolve(ExtensionManagementSurfaceRegistry::class)->packageNames();
    }
}

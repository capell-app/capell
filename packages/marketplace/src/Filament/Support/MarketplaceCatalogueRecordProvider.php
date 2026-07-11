<?php

declare(strict_types=1);

namespace Capell\Marketplace\Filament\Support;

use Capell\Admin\Contracts\Extensions\ExtensionCatalogueMetadataProvider;
use Capell\Admin\Data\Extensions\ExtensionCatalogueMetadataData;
use Capell\Core\Data\PackageData;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Marketplace\MarketplaceAssetUrl;
use Capell\Marketplace\Actions\QueueMarketplaceCatalogueWarmAction;
use Capell\Marketplace\Actions\ResolveMarketplaceInstallEligibilityAction;
use Capell\Marketplace\Data\ExtensionListingData;
use Capell\Marketplace\Data\MarketplaceCataloguePageData;
use Capell\Marketplace\Data\MarketplaceCatalogueQueryData;
use Capell\Marketplace\Enums\ExtensionKind;
use Capell\Marketplace\Enums\MarketplaceExtensionCapability;
use Capell\Marketplace\Enums\MarketplaceExtensionCategory;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Enums\MarketplaceSort;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Capell\Marketplace\Models\MarketplaceInstance;
use Capell\Marketplace\Services\MarketplaceClient;
use Capell\Marketplace\Services\VersionCompatibilityChecker;
use Capell\Marketplace\Support\MarketplaceInstanceResolver;
use Composer\InstalledVersions;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class MarketplaceCatalogueRecordProvider implements ExtensionCatalogueMetadataProvider
{
    /** @var array<int, int> */
    private const TABLE_PAGE_OPTIONS = [18, 36, 72];

    private const int DEFAULT_TABLE_PAGE_OPTION = 18;

    private const int MAX_REMOTE_PAGE = 100;

    private const array INTERNAL_MARKETPLACE_COMPOSER_NAMES = [
        'capell-app/installer',
        'capell-app/marketplace',
        'capell-app/plugins',
    ];

    private const int DEFAULT_MAX_REMOTE_PAGE = 3;

    private bool $marketplaceBrowseUnavailable = false;

    private ?string $marketplaceBrowseUnavailableReason = null;

    /** @var Collection<int, PackageData>|null */
    private ?Collection $downloadedExtensions = null;

    /** @var list<string>|null */
    private ?array $downloadedComposerNames = null;

    /** @var array<string, ?string> */
    private array $installedPluginVersions = [];

    /** @var array<string, MarketplaceInstallAttempt>|null */
    private ?array $activeInstallOperationsByComposerName = null;

    public function __construct(
        private readonly MarketplaceInstallActionPresenter $installActionPresenter,
        private readonly MarketplaceInstanceResolver $instances,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function records(
        ?string $search = null,
        array $filters = [],
        ?string $lockedKind = null,
        bool $includeLocalExtensionState = true,
    ): array {
        return $this->recordsForPage(
            search: $search,
            filters: $filters,
            lockedKind: $lockedKind,
            includeLocalExtensionState: $includeLocalExtensionState,
        )->extensions;
    }

    /**
     * @param  array<int, string>  $composerNames
     * @return array<string, array<string, mixed>>
     */
    public function recordsByComposerNames(
        array $composerNames,
        ?string $lockedKind = null,
        bool $includeLocalExtensionState = true,
    ): array {
        $composerNames = collect($composerNames)
            ->map(fn (string $composerName): ?string => ExtensionListingData::localPackageComposerName(trim($composerName)))
            ->filter(fn (?string $composerName): bool => is_string($composerName) && $composerName !== '')
            ->unique()
            ->values();

        if ($composerNames->isEmpty()) {
            return [];
        }

        $compatibilityVersions = $this->detectedCompatibilityVersions();
        $includeLocalExtensionState = $this->canExposeLocalExtensionState($includeLocalExtensionState);
        $kind = $this->lockedMarketplaceKind($lockedKind) ?? '';
        $records = [];
        $marketplaceClient = resolve(MarketplaceClient::class);

        try {
            $extensions = $marketplaceClient->extensionsByComposerNames(
                composerNames: $composerNames->all(),
                kind: $kind,
                capellVersion: $compatibilityVersions['capell'],
                laravelVersion: $compatibilityVersions['laravel'],
                livewireVersion: $compatibilityVersions['livewire'],
                filamentVersion: $compatibilityVersions['filament'],
            );

            foreach ($extensions as $composerName => $extension) {
                if ($this->isHiddenMarketplaceExtension($extension)) {
                    continue;
                }

                $records[$composerName] = $this->extensionTableRecord($extension, $includeLocalExtensionState);
            }
        } catch (Throwable $throwable) {
            Log::warning('capell-marketplace: exact marketplace composer lookup failed; falling back to search lookup', [
                'error' => $throwable->getMessage(),
                'composer_names' => $composerNames->all(),
            ]);
        }

        foreach ($composerNames as $composerName) {
            if (array_key_exists($composerName, $records)) {
                continue;
            }

            $extensions = $marketplaceClient->listExtensions(
                search: $composerName,
                kind: $kind,
                capellVersion: $compatibilityVersions['capell'],
                laravelVersion: $compatibilityVersions['laravel'],
                livewireVersion: $compatibilityVersions['livewire'],
                filamentVersion: $compatibilityVersions['filament'],
                maxPages: 1,
            );

            foreach ($extensions as $extension) {
                if ($extension->composerName !== $composerName) {
                    continue;
                }

                if ($this->isHiddenMarketplaceExtension($extension)) {
                    continue;
                }

                $records[$composerName] = $this->extensionTableRecord($extension, $includeLocalExtensionState);

                break;
            }
        }

        return $records;
    }

    /**
     * @param  list<string>  $composerNames
     * @return array<string, ExtensionCatalogueMetadataData>
     */
    public function metadataForComposerNames(array $composerNames): array
    {
        $normalizedComposerNamesByRequestedName = [];

        foreach ($composerNames as $composerName) {
            $requestedComposerName = trim($composerName);
            $normalizedComposerName = ExtensionListingData::localPackageComposerName($requestedComposerName);

            if ($requestedComposerName === '' || $normalizedComposerName === null || $normalizedComposerName === '') {
                continue;
            }

            $normalizedComposerNamesByRequestedName[$requestedComposerName] = $normalizedComposerName;
        }

        $composerNames = array_values(array_unique(array_values($normalizedComposerNamesByRequestedName)));

        if ($composerNames === []) {
            return [];
        }

        $compatibilityVersions = $this->detectedCompatibilityVersions();

        try {
            $extensions = resolve(MarketplaceClient::class)->extensionsByComposerNames(
                composerNames: $composerNames,
                capellVersion: $compatibilityVersions['capell'],
                laravelVersion: $compatibilityVersions['laravel'],
                livewireVersion: $compatibilityVersions['livewire'],
                filamentVersion: $compatibilityVersions['filament'],
            );
        } catch (Throwable $throwable) {
            Log::warning('capell-marketplace: installed extension catalogue metadata lookup failed', [
                'error' => $throwable->getMessage(),
                'composer_names' => $composerNames,
            ]);

            return [];
        }

        $metadataByComposerName = [];

        foreach ($extensions as $composerName => $extension) {
            if ($this->isHiddenMarketplaceExtension($extension)) {
                continue;
            }

            $metadataByComposerName[$composerName] = new ExtensionCatalogueMetadataData(
                catalogueRole: $extension->catalogueRole,
                maturity: $extension->maturity,
                maturityLabel: $extension->maturityLabel,
                includedWithCapellAll: $extension->includedWithCapellAll,
            );
        }

        $metadata = [];

        foreach ($normalizedComposerNamesByRequestedName as $requestedComposerName => $normalizedComposerName) {
            if (array_key_exists($normalizedComposerName, $metadataByComposerName)) {
                $metadata[$requestedComposerName] = $metadataByComposerName[$normalizedComposerName];
            }
        }

        return $metadata;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginatedRecords(
        ?string $search = null,
        array $filters = [],
        ?string $lockedKind = null,
        int $page = 1,
        int $perPage = self::DEFAULT_TABLE_PAGE_OPTION,
        bool $includeLocalExtensionState = true,
    ): LengthAwarePaginator {
        $marketplacePage = $this->recordsForPage(
            search: $search,
            filters: $filters,
            lockedKind: $lockedKind,
            page: $this->tablePageValue($page),
            perPage: $this->tableRecordsPerPageValue($perPage),
            allowStale: true,
            includeLocalExtensionState: $includeLocalExtensionState,
        );

        return new LengthAwarePaginator(
            items: $marketplacePage->extensions,
            total: $marketplacePage->total,
            perPage: $marketplacePage->perPage,
            currentPage: $marketplacePage->currentPage,
            options: [
                'path' => request()->url(),
            ],
        );
    }

    public function queueDefaultWarm(?string $lockedKind = null, bool $includeLocalExtensionState = true): bool
    {
        $compatibilityVersions = $this->detectedCompatibilityVersions();
        $includeLocalExtensionState = $this->canExposeLocalExtensionState($includeLocalExtensionState);

        return QueueMarketplaceCatalogueWarmAction::run(new MarketplaceCatalogueQueryData(
            kind: $this->lockedMarketplaceKind($lockedKind) ?? '',
            sort: MarketplaceClient::DEFAULT_EXTENSION_SORT,
            capellVersion: $compatibilityVersions['capell'],
            laravelVersion: $compatibilityVersions['laravel'],
            livewireVersion: $compatibilityVersions['livewire'],
            filamentVersion: $compatibilityVersions['filament'],
            installedStatus: $includeLocalExtensionState ? 'available' : '',
            installedComposerNames: $includeLocalExtensionState ? $this->getDownloadedComposerNames() : [],
            page: 1,
            perPage: self::DEFAULT_TABLE_PAGE_OPTION,
            includeMarketplaceContext: $includeLocalExtensionState,
        ));
    }

    public function marketplaceBrowseUnavailable(): bool
    {
        return $this->marketplaceBrowseUnavailable;
    }

    public function marketplaceBrowseUnavailableReason(): ?string
    {
        return $this->marketplaceBrowseUnavailableReason;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function recordsForPage(
        ?string $search = null,
        array $filters = [],
        ?string $lockedKind = null,
        int $page = 1,
        int $perPage = self::DEFAULT_TABLE_PAGE_OPTION,
        bool $allowStale = false,
        bool $includeLocalExtensionState = true,
    ): MarketplaceCataloguePageData {
        $compatibilityVersions = $this->detectedCompatibilityVersions();
        $includeLocalExtensionState = $this->canExposeLocalExtensionState($includeLocalExtensionState);
        $kind = $this->lockedMarketplaceKind($lockedKind) ?? $this->filterValue($filters, 'kind');
        $sort = $this->validSort($this->filterValue($filters, 'sort') ?? MarketplaceClient::DEFAULT_EXTENSION_SORT);
        $installedStatus = array_key_exists('installed_status', $filters)
            ? $this->selectedInstalledStatus($filters['installed_status']['value'] ?? null)
            : 'not_installed';

        $query = new MarketplaceCatalogueQueryData(
            search: trim($search ?? ''),
            kind: $this->validKind($kind),
            freeOnly: (bool) ($filters['free_only']['isActive'] ?? false),
            sort: $sort,
            priceMinCents: $this->moneyFilterToCents($filters['price']['price_min'] ?? null),
            priceMaxCents: $this->moneyFilterToCents($filters['price']['price_max'] ?? null),
            capellVersion: $this->filterValue($filters, 'compatibility', 'capell_version') ?? $compatibilityVersions['capell'],
            laravelVersion: $this->filterValue($filters, 'compatibility', 'laravel_version') ?? $compatibilityVersions['laravel'],
            livewireVersion: $this->filterValue($filters, 'compatibility', 'livewire_version') ?? $compatibilityVersions['livewire'],
            filamentVersion: $this->filterValue($filters, 'compatibility', 'filament_version') ?? $compatibilityVersions['filament'],
            category: $this->validCategory($this->filterValue($filters, 'category')),
            capabilities: $this->validCapabilities($this->filterValues($filters, 'capability')),
            author: $this->filterValue($filters, 'author', 'author_slug') ?? $this->filterValue($filters, 'author', 'author'),
            installedStatus: $includeLocalExtensionState ? $this->queryInstalledStatus($installedStatus) : '',
            installedComposerNames: $includeLocalExtensionState ? $this->getDownloadedComposerNames() : [],
            page: $page,
            perPage: $perPage,
            includeMarketplaceContext: $includeLocalExtensionState,
        );

        $resolvedPage = $this->visibleMarketplacePage(
            query: $query,
            installedStatus: $installedStatus,
            allowStale: $allowStale,
            includeLocalExtensionState: $includeLocalExtensionState,
        );

        return new MarketplaceCataloguePageData(
            extensions: $resolvedPage['records'],
            total: $resolvedPage['total'],
            currentPage: $query->page,
            perPage: $query->perPage,
            nextPageUrl: $resolvedPage['next_page_url'],
            stale: $resolvedPage['stale'],
        );
    }

    /**
     * @return array{records: array<int, array<string, mixed>>, total: int, next_page_url: ?string, stale: bool}
     */
    private function visibleMarketplacePage(
        MarketplaceCatalogueQueryData $query,
        ?string $installedStatus,
        bool $allowStale,
        bool $includeLocalExtensionState,
    ): array {
        if ($query->page > 1) {
            return $this->singleRemoteMarketplacePage(
                query: $query,
                installedStatus: $installedStatus,
                allowStale: $allowStale,
                includeLocalExtensionState: $includeLocalExtensionState,
            );
        }

        $targetVisibleOffset = max(0, ($query->page - 1) * $query->perPage);
        $visibleSeen = 0;
        $hiddenSeen = 0;
        $records = [];
        $remotePageNumber = 1;
        $lastRemotePage = null;
        $nextPageUrl = null;
        $stale = false;

        do {
            $pageQuery = $this->queryForPage($query, $remotePageNumber);
            $remotePage = $this->fetchMarketplaceExtensionPage($pageQuery, $allowStale);
            $lastRemotePage = $remotePage;
            $nextPageUrl = $remotePage->nextPageUrl;
            $stale = $stale || $remotePage->stale;

            $visibleExtensions = collect($remotePage->extensions)
                ->reject(fn (ExtensionListingData $extension): bool => $this->isHiddenMarketplaceExtension($extension))
                ->filter(fn (ExtensionListingData $extension): bool => $this->matchesInstallAvailability($extension, $installedStatus, $includeLocalExtensionState))
                ->values();

            $hiddenSeen += count($remotePage->extensions) - $visibleExtensions->count();

            foreach ($visibleExtensions as $extension) {
                if ($visibleSeen++ < $targetVisibleOffset) {
                    continue;
                }

                if (count($records) >= $query->perPage) {
                    break;
                }

                $records[] = $this->extensionTableRecord($extension, $includeLocalExtensionState);
            }

            $hasMoreRemotePages = $remotePage->nextPageUrl !== null
                || ($remotePageNumber * $query->perPage) < $remotePage->total;
            $remotePageNumber++;
        } while (count($records) < $query->perPage && $hiddenSeen > 0 && $hasMoreRemotePages && $remotePageNumber <= $this->maxRemotePages());

        $remoteTotal = $lastRemotePage->total;

        return [
            'records' => $records,
            'total' => max($targetVisibleOffset + count($records), $remoteTotal - $hiddenSeen),
            'next_page_url' => $nextPageUrl,
            'stale' => $stale,
        ];
    }

    /**
     * @return array{records: array<int, array<string, mixed>>, total: int, next_page_url: ?string, stale: bool}
     */
    private function singleRemoteMarketplacePage(
        MarketplaceCatalogueQueryData $query,
        ?string $installedStatus,
        bool $allowStale,
        bool $includeLocalExtensionState,
    ): array {
        $marketplacePage = $this->fetchMarketplaceExtensionPage($query, $allowStale);
        $visibleExtensions = collect($marketplacePage->extensions)
            ->reject(fn (ExtensionListingData $extension): bool => $this->isHiddenMarketplaceExtension($extension))
            ->filter(fn (ExtensionListingData $extension): bool => $this->matchesInstallAvailability($extension, $installedStatus, $includeLocalExtensionState))
            ->values();
        $hiddenExtensionsCount = count($marketplacePage->extensions) - $visibleExtensions->count();

        return [
            'records' => $visibleExtensions
                ->map(fn (ExtensionListingData $extension): array => $this->extensionTableRecord($extension, $includeLocalExtensionState))
                ->values()
                ->all(),
            'total' => max($visibleExtensions->count(), $marketplacePage->total - $hiddenExtensionsCount),
            'next_page_url' => $marketplacePage->nextPageUrl,
            'stale' => $marketplacePage->stale,
        ];
    }

    /** @return Collection<int, PackageData> */
    private function getDownloadedExtensions(): Collection
    {
        return $this->downloadedExtensions ??= CapellCore::getPackages()
            ->filter(fn (PackageData $package): bool => CapellCore::isPackageInstalled($package->name)
                || CapellCore::isPackageAvailable($package->name))
            ->values();
    }

    /** @return list<string> */
    private function getDownloadedComposerNames(): array
    {
        return $this->downloadedComposerNames ??= array_values($this->getDownloadedExtensions()
            ->pluck('name')
            ->merge($this->activeInstallComposerNames())
            ->unique()
            ->values()
            ->all());
    }

    /** @return list<string> */
    private function activeInstallComposerNames(): array
    {
        return array_keys($this->activeInstallOperations());
    }

    private function isInstalled(ExtensionListingData $extension): bool
    {
        return array_any(ExtensionListingData::localPackageComposerNameCandidates($extension->composerName), fn ($composerName): bool => CapellCore::hasPackage($composerName) && CapellCore::isPackageInstalled($composerName));
    }

    private function matchesInstallAvailability(ExtensionListingData $extension, ?string $installedStatus, bool $includeLocalExtensionState): bool
    {
        if (! $includeLocalExtensionState) {
            return true;
        }

        return match ($installedStatus) {
            'installed' => $this->isInstalled($extension),
            'not_installed' => ! $this->isInstalled($extension),
            default => true,
        };
    }

    private function queryForPage(MarketplaceCatalogueQueryData $query, int $page): MarketplaceCatalogueQueryData
    {
        return new MarketplaceCatalogueQueryData(
            search: $query->search,
            kind: $query->kind,
            freeOnly: $query->freeOnly,
            sort: $query->sort,
            priceMinCents: $query->priceMinCents,
            priceMaxCents: $query->priceMaxCents,
            capellVersion: $query->capellVersion,
            laravelVersion: $query->laravelVersion,
            livewireVersion: $query->livewireVersion,
            filamentVersion: $query->filamentVersion,
            category: $query->category,
            capabilities: $query->capabilities,
            author: $query->author,
            installedStatus: $query->installedStatus,
            installedComposerNames: $query->installedComposerNames,
            page: $page,
            perPage: $query->perPage,
            includeMarketplaceContext: $query->includeMarketplaceContext,
        );
    }

    private function maxRemotePages(): int
    {
        return max(
            1,
            (int) config(
                'capell-marketplace.marketplace.max_remote_pages_per_interactive_request',
                self::DEFAULT_MAX_REMOTE_PAGE,
            ),
        );
    }

    private function installedPluginVersion(string $composerName): ?string
    {
        if (array_key_exists($composerName, $this->installedPluginVersions)) {
            return $this->installedPluginVersions[$composerName];
        }

        foreach (ExtensionListingData::localPackageComposerNameCandidates($composerName) as $candidateComposerName) {
            if (CapellCore::hasPackage($candidateComposerName)) {
                return $this->installedPluginVersions[$composerName] = CapellCore::getPackage($candidateComposerName)->version;
            }
        }

        return $this->installedPluginVersions[$composerName] = null;
    }

    /** @return array{capell: ?string, laravel: ?string, livewire: ?string, filament: ?string} */
    private function detectedCompatibilityVersions(): array
    {
        return [
            'capell' => CapellCore::getInstalledPrettyVersion('capell-app/capell')
                ?? CapellCore::getInstalledPrettyVersion('capell/core'),
            'laravel' => $this->installedPackagePrettyVersion('laravel/framework') ?? app()->version(),
            'livewire' => $this->installedPackagePrettyVersion('livewire/livewire'),
            'filament' => $this->installedPackagePrettyVersion('filament/filament'),
        ];
    }

    private function canExposeLocalExtensionState(bool $requested): bool
    {
        if (! $requested) {
            return false;
        }

        return auth()->user() !== null;
    }

    private function fetchMarketplaceExtensionPage(MarketplaceCatalogueQueryData $query, bool $allowStale): MarketplaceCataloguePageData
    {
        try {
            $this->marketplaceBrowseUnavailable = false;
            $this->marketplaceBrowseUnavailableReason = null;
            $marketplacePage = resolve(MarketplaceClient::class)->listExtensionPage($query, allowStale: $allowStale);

            if ($marketplacePage->stale) {
                QueueMarketplaceCatalogueWarmAction::run($query);
            }

            return $marketplacePage;
        } catch (Throwable $throwable) {
            $this->marketplaceBrowseUnavailable = true;
            $this->marketplaceBrowseUnavailableReason = $throwable->getMessage() !== '' ? $throwable->getMessage() : null;

            Log::warning('capell-marketplace: marketplace browse failed', ['error' => $throwable->getMessage()]);

            return new MarketplaceCataloguePageData(
                extensions: [],
                total: 0,
                currentPage: $query->page,
                perPage: $query->perPage,
            );
        }
    }

    /** @return array<string, mixed> */
    private function extensionTableRecord(ExtensionListingData $extension, bool $includeLocalExtensionState = true): array
    {
        $isInstalled = $includeLocalExtensionState && $this->isInstalled($extension);
        $installedVersion = $isInstalled ? $this->installedPluginVersion($extension->composerName) : null;
        $compatibilityDetails = resolve(VersionCompatibilityChecker::class)->compatibilityDetails($extension);
        $purchaseUrl = $this->trustedMarketplaceUrl($extension->purchaseUrl);
        $isCompatible = ! in_array('incompatible', $compatibilityDetails, true);
        $activeInstallOperation = $includeLocalExtensionState
            ? $this->activeInstallOperation($extension->composerName)
            : null;
        $eligibility = ResolveMarketplaceInstallEligibilityAction::run(
            listing: $extension,
            instance: $this->latestMarketplaceInstance(),
            action: 'install',
            remoteEligibility: $extension->installEligibilityPolicy,
        );

        return [
            'key' => $extension->slug,
            'slug' => $extension->slug,
            'name' => $extension->name,
            'composer_name' => $extension->composerName,
            'kind' => $extension->kind,
            'product_group' => $extension->productGroup,
            'product_tier' => $extension->productTier,
            'product_bundle' => $extension->productBundle,
            'catalogue_role' => $extension->catalogueRole,
            'maturity' => $extension->maturity,
            'maturity_label' => $extension->maturityLabel,
            'included_with_capell_all' => $extension->includedWithCapellAll,
            'effective_certification' => $extension->effectiveCertification,
            'support_policy' => $extension->supportPolicy,
            'description' => $extension->description,
            'image_url' => $this->marketplaceImageUrl($extension->imageUrl),
            'image_urls' => array_values(array_filter(array_map(
                $this->marketplaceImageUrl(...),
                $extension->imageUrls,
            ))),
            'price_cents' => $extension->priceCents,
            'price_label' => $this->priceLabel($extension),
            'is_paid' => $extension->isPaid,
            'is_featured' => $extension->isFeatured,
            'featured_rank' => $extension->featuredRank,
            'is_publisher_verified' => $extension->publisherVerified,
            'is_security_reviewed' => $extension->securityReviewed,
            'latest_version' => $extension->latestVersion,
            'released_at_label' => $extension->releasedAt?->toFormattedDateString(),
            'author_name' => $extension->authorName,
            'author_filter' => $extension->authorSlug ?? $extension->authorName,
            'rating_average' => $extension->ratingAverage,
            'rating_average_label' => $this->ratingAverageLabel($extension->ratingAverage),
            'rating_stars' => $this->ratingStars($extension->ratingAverage),
            'ratings_count' => $extension->ratingsCount,
            'ratings_count_label' => $this->ratingsCountLabel($extension->ratingsCount),
            'is_installed' => $isInstalled,
            'installed_version' => $installedVersion,
            'has_update_available' => $includeLocalExtensionState && $this->hasUpdateAvailable($installedVersion, $extension->latestVersion),
            'documentation_url' => $this->trustedMarketplaceUrl($extension->documentationUrl),
            'purchase_url' => $purchaseUrl,
            'requires_confirmation' => $extension->requiresConfirmation,
            'install_confirmation' => $extension->installConfirmation,
            'install_options' => $extension->installOptions,
            'required_dependencies' => $extension->requiredDependencies,
            'capell_version_constraint' => $extension->capellVersionConstraint,
            'laravel_version_constraint' => $extension->laravelVersionConstraint,
            'filament_version_constraint' => $extension->filamentVersionConstraint,
            'livewire_version_constraint' => $extension->livewireVersionConstraint,
            'category_labels' => $this->categoryLabels($extension->categories),
            'capability_labels' => $this->capabilityLabels($extension->capabilities),
            'surface_labels' => $this->stateLabels($extension->surfaces),
            'contribution_count' => array_sum($extension->contributionSummary),
            'is_compatible' => $isCompatible,
            'compatibility_warnings' => $this->compatibilityWarnings($compatibilityDetails),
            'activation_required' => $extension->activationRequired,
            'install_authorized' => $extension->installAuthorized,
            'install_eligibility_policy' => $eligibility->toArray(),
            'install_in_progress' => $activeInstallOperation instanceof MarketplaceInstallAttempt,
            'active_install_operation_id' => $activeInstallOperation?->getKey(),
            'active_install_operation_status' => $activeInstallOperation?->status->value,
            'primary_action' => $extension->primaryAction,
            'marketplace_install_state' => $this->installActionPresenter->state([
                'is_installed' => $isInstalled,
                'is_compatible' => $isCompatible,
                'is_paid' => $extension->isPaid,
                'marketplace_install_state' => $extension->installState,
                'activation_required' => $extension->activationRequired,
                'install_authorized' => $extension->installAuthorized,
                'install_eligibility_policy' => $eligibility->toArray(),
                'purchase_url' => $purchaseUrl,
                'install_in_progress' => $activeInstallOperation instanceof MarketplaceInstallAttempt,
            ])->value,
        ];
    }

    private function activeInstallOperation(string $composerName): ?MarketplaceInstallAttempt
    {
        foreach (ExtensionListingData::localPackageComposerNameCandidates($composerName) as $candidateComposerName) {
            if (array_key_exists($candidateComposerName, $this->activeInstallOperations())) {
                return $this->activeInstallOperations()[$candidateComposerName];
            }
        }

        return null;
    }

    /** @return array<string, MarketplaceInstallAttempt> */
    private function activeInstallOperations(): array
    {
        if ($this->activeInstallOperationsByComposerName !== null) {
            return $this->activeInstallOperationsByComposerName;
        }

        return $this->activeInstallOperationsByComposerName = MarketplaceInstallAttempt::query()
            ->whereIn('status', [
                MarketplaceInstallIntentStatus::Queued->value,
                MarketplaceInstallIntentStatus::Running->value,
                MarketplaceInstallIntentStatus::CancelRequested->value,
            ])
            ->latest('updated_at')
            ->get()
            ->unique('composer_name')
            ->keyBy('composer_name')
            ->all();
    }

    private function priceLabel(ExtensionListingData $extension): string
    {
        if (! $extension->isPaid || $this->isFreeProductTier($extension)) {
            return (string) __('capell-marketplace::marketplace.install.free');
        }

        return '$' . number_format($extension->priceCents / 100, 2);
    }

    private function isFreeProductTier(ExtensionListingData $extension): bool
    {
        return str($extension->productTier ?? '')->lower()->toString() === 'free';
    }

    private function latestMarketplaceInstance(): ?MarketplaceInstance
    {
        return $this->instances->latest();
    }

    private function ratingAverageLabel(?float $ratingAverage): string
    {
        if ($ratingAverage === null) {
            return (string) __('capell-marketplace::marketplace.card.no_rating');
        }

        return number_format($ratingAverage, 1);
    }

    /** @return list<string> */
    private function ratingStars(?float $ratingAverage): array
    {
        $roundedRating = $ratingAverage === null ? 0.0 : round($ratingAverage * 2) / 2;

        return array_values(collect(range(1, 5))
            ->map(function (int $starPosition) use ($roundedRating): string {
                if ($roundedRating >= $starPosition) {
                    return 'full';
                }

                if ($roundedRating >= $starPosition - 0.5) {
                    return 'half';
                }

                return 'empty';
            })
            ->all());
    }

    private function ratingsCountLabel(int $ratingsCount): string
    {
        return trans_choice('capell-marketplace::marketplace.card.ratings_count', $ratingsCount, [
            'count' => number_format($ratingsCount),
        ]);
    }

    private function hasUpdateAvailable(?string $installedVersion, ?string $latestVersion): bool
    {
        if (! $this->isComparableVersion($installedVersion) || ! $this->isComparableVersion($latestVersion)) {
            return false;
        }

        return version_compare(ltrim((string) $installedVersion, 'v'), ltrim((string) $latestVersion, 'v'), '<');
    }

    private function isComparableVersion(?string $version): bool
    {
        return is_string($version) && preg_match('/^v?\d+(?:\.\d+){0,3}(?:[-+][0-9A-Za-z.-]+)?$/', $version) === 1;
    }

    /**
     * @param  list<string>  $categories
     * @return list<string>
     */
    private function categoryLabels(array $categories): array
    {
        return array_values(collect($categories)
            ->map(fn (string $category): string => MarketplaceExtensionCategory::tryFrom($category)?->getLabel() ?? Str::headline($category))
            ->values()
            ->all());
    }

    /**
     * @param  array<string, mixed>  $capabilities
     * @return list<string>
     */
    private function capabilityLabels(array $capabilities): array
    {
        return array_values(collect($this->capabilitySlugs($capabilities))
            ->map(fn (string $capability): string => MarketplaceExtensionCapability::tryFrom($capability)?->getLabel() ?? Str::headline($capability))
            ->values()
            ->all());
    }

    /**
     * @param  list<string>  $states
     * @return list<string>
     */
    private function stateLabels(array $states): array
    {
        return array_values(collect($states)
            ->map(fn (string $state): string => Str::of($state)->replace(['-', '_'], ' ')->headline()->toString())
            ->values()
            ->all());
    }

    /**
     * @param  array<string, mixed>  $capabilities
     * @return list<string>
     */
    private function capabilitySlugs(array $capabilities): array
    {
        $slugs = [];

        foreach ($capabilities as $capabilityKey => $capabilityValue) {
            if (is_string($capabilityKey) && $capabilityKey !== '' && $capabilityValue !== false && $capabilityValue !== null) {
                $slugs[] = Str::snake($capabilityKey);

                continue;
            }

            if (is_array($capabilityValue)) {
                $capabilitySlug = $capabilityValue['slug'] ?? $capabilityValue['key'] ?? null;

                if (is_scalar($capabilitySlug) && (string) $capabilitySlug !== '') {
                    $slugs[] = Str::snake((string) $capabilitySlug);
                }

                continue;
            }

            if (is_scalar($capabilityValue) && (string) $capabilityValue !== '') {
                $slugs[] = Str::snake((string) $capabilityValue);
            }
        }

        return array_values(array_unique($slugs));
    }

    /**
     * @param  array<string, string>  $compatibilityDetails
     * @return list<string>
     */
    private function compatibilityWarnings(array $compatibilityDetails): array
    {
        return array_values(collect($compatibilityDetails)
            ->filter(fn (string $status): bool => $status === 'incompatible')
            ->keys()
            ->map(fn (string $platform): string => (string) __('capell-marketplace::marketplace.card.incompatible_platform', [
                'platform' => (string) __('capell-marketplace::marketplace.platform-builder.' . $platform),
            ]))
            ->values()
            ->all());
    }

    private function filterValue(array $filters, string $filter, string $field = 'value'): ?string
    {
        $value = $filters[$filter][$field] ?? null;

        return is_scalar($value) && (string) $value !== ''
            ? (string) $value
            : null;
    }

    private function tablePageValue(int|string $value): int
    {
        if (! is_numeric($value) || (int) $value < 1) {
            return 1;
        }

        return min((int) $value, self::MAX_REMOTE_PAGE);
    }

    private function tableRecordsPerPageValue(int|string $value): int
    {
        return is_numeric($value) && in_array((int) $value, self::TABLE_PAGE_OPTIONS, true)
            ? (int) $value
            : self::DEFAULT_TABLE_PAGE_OPTION;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<string>
     */
    private function filterValues(array $filters, string $filter): array
    {
        $values = $filters[$filter]['values'] ?? [];

        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (mixed $value): ?string => is_scalar($value) && (string) $value !== '' ? (string) $value : null,
            $values,
        ), is_string(...)));
    }

    private function moneyFilterToCents(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $amount = (float) $value;

        if ($amount < 0) {
            return null;
        }

        return (int) round($amount * 100);
    }

    private function installedPackagePrettyVersion(string $packageName): ?string
    {
        try {
            return InstalledVersions::isInstalled($packageName)
                ? InstalledVersions::getPrettyVersion($packageName)
                : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function validKind(?string $kind): string
    {
        return ExtensionKind::tryFrom((string) $kind) instanceof ExtensionKind
            ? (string) $kind
            : '';
    }

    private function lockedMarketplaceKind(?string $lockedKind): ?string
    {
        $kind = $this->validKind($lockedKind);

        return $kind !== '' ? $kind : null;
    }

    private function validSort(?string $sort): string
    {
        return MarketplaceSort::tryFrom((string) $sort) instanceof MarketplaceSort
            ? (string) $sort
            : MarketplaceClient::DEFAULT_EXTENSION_SORT;
    }

    private function selectedInstalledStatus(mixed $installedStatus): string
    {
        return match ($installedStatus) {
            true => 'installed',
            false => 'not_installed',
            'installed' => 'installed',
            'not_installed', 'available' => 'not_installed',
            default => '',
        };
    }

    private function queryInstalledStatus(string $installedStatus): string
    {
        return $installedStatus === 'installed' ? 'installed' : '';
    }

    private function validCategory(?string $category): ?string
    {
        return MarketplaceExtensionCategory::tryFrom((string) $category) instanceof MarketplaceExtensionCategory
            ? (string) $category
            : null;
    }

    /**
     * @param  list<string>  $capabilities
     * @return list<string>
     */
    private function validCapabilities(array $capabilities): array
    {
        return array_values(array_filter(
            $capabilities,
            fn (string $capability): bool => MarketplaceExtensionCapability::tryFrom($capability) instanceof MarketplaceExtensionCapability,
        ));
    }

    private function isHiddenMarketplaceExtension(ExtensionListingData $extension): bool
    {
        return in_array($extension->composerName, $this->hiddenMarketplaceComposerNames(), true)
            || $this->isInProgressMarketplaceExtension($extension);
    }

    private function isInProgressMarketplaceExtension(ExtensionListingData $extension): bool
    {
        $status = collect([
            $extension->metadata['status'] ?? null,
            $extension->metadata['marketplace_status'] ?? null,
            $extension->metadata['listing_status'] ?? null,
        ])->first(fn (mixed $value): bool => is_scalar($value) && (string) $value !== '');

        if (! is_scalar($status)) {
            return false;
        }

        return str((string) $status)
            ->lower()
            ->replace(['-', ' '], '_')
            ->toString() === 'in_progress';
    }

    /** @return list<string> */
    private function hiddenMarketplaceComposerNames(): array
    {
        return array_values(CapellCore::getPackages()
            ->filter(fn (PackageData $package): bool => $package->isHiddenFromMarketplace())
            ->keys()
            ->merge(self::INTERNAL_MARKETPLACE_COMPOSER_NAMES)
            ->unique()
            ->values()
            ->all());
    }

    private function trustedMarketplaceUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        $urlParts = parse_url($url);

        if (! is_array($urlParts)) {
            return null;
        }

        $schemeValue = $urlParts['scheme'] ?? '';
        $hostValue = $urlParts['host'] ?? '';
        $scheme = is_string($schemeValue) ? strtolower($schemeValue) : '';
        $host = is_string($hostValue) ? strtolower($hostValue) : '';

        if ($scheme !== 'https' || $host === '') {
            return null;
        }

        return in_array($host, $this->trustedMarketplaceHosts(), true) ? $url : null;
    }

    private function marketplaceImageUrl(?string $url): ?string
    {
        return MarketplaceAssetUrl::toUrl($url);
    }

    /** @return list<string> */
    private function trustedMarketplaceHosts(): array
    {
        return array_values(collect([
            config('capell-marketplace.marketplace.web_url'),
            config('capell.marketplace_web_url'),
            config('capell-marketplace.marketplace.base_url'),
        ])
            ->filter(fn (mixed $url): bool => is_string($url))
            ->map(function (string $url): string {
                $host = parse_url($url, PHP_URL_HOST);

                return is_string($host) ? strtolower($host) : '';
            })
            ->filter(fn (string $host): bool => $host !== '')
            ->unique()
            ->values()
            ->all());
    }
}

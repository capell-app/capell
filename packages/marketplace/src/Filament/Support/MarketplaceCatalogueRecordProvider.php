<?php

declare(strict_types=1);

namespace Capell\Marketplace\Filament\Support;

use Capell\Admin\Contracts\Extensions\ExtensionCatalogueMetadataProvider;
use Capell\Admin\Data\Extensions\ExtensionCatalogueMetadataData;
use Capell\Core\Data\PackageData;
use Capell\Core\Facades\CapellCore;
use Capell\Marketplace\Actions\BuildMarketplaceCatalogueLocalStateSnapshotAction;
use Capell\Marketplace\Actions\FetchMarketplaceCataloguePageAction;
use Capell\Marketplace\Actions\ProjectMarketplaceCatalogueRecordAction;
use Capell\Marketplace\Actions\QueueMarketplaceCatalogueWarmAction;
use Capell\Marketplace\Actions\ResolveMarketplaceCatalogueLocalStateAction;
use Capell\Marketplace\Contracts\MarketplaceSelectionRecordProvider;
use Capell\Marketplace\Data\ExtensionListingData;
use Capell\Marketplace\Data\MarketplaceCatalogueLocalStateData;
use Capell\Marketplace\Data\MarketplaceCatalogueLocalStateSnapshotData;
use Capell\Marketplace\Data\MarketplaceCataloguePageData;
use Capell\Marketplace\Data\MarketplaceCatalogueQueryData;
use Capell\Marketplace\Data\MarketplaceSelectionRecordData;
use Capell\Marketplace\Enums\ExtensionKind;
use Capell\Marketplace\Enums\MarketplaceExtensionCapability;
use Capell\Marketplace\Enums\MarketplaceExtensionCategory;
use Capell\Marketplace\Enums\MarketplaceSort;
use Capell\Marketplace\Services\MarketplaceClient;
use Capell\Marketplace\Support\MarketplaceInstanceResolver;
use Composer\InstalledVersions;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

final class MarketplaceCatalogueRecordProvider implements ExtensionCatalogueMetadataProvider, MarketplaceSelectionRecordProvider
{
    /** @var array<int, int> */
    public const TABLE_PAGE_OPTIONS = [18, 36, 72];

    public const int DEFAULT_TABLE_PAGE_OPTION = 18;

    private const int MAX_REMOTE_PAGE = 100;

    private const array INTERNAL_MARKETPLACE_COMPOSER_NAMES = [
        'capell-app/installer',
        'capell-app/marketplace',
        'capell-app/plugins',
    ];

    private const int DEFAULT_MAX_REMOTE_PAGE = 3;

    private bool $marketplaceBrowseUnavailable = false;

    private ?string $marketplaceBrowseUnavailableReason = null;

    private ?MarketplaceCatalogueLocalStateSnapshotData $localStateSnapshot = null;

    public function __construct(
        private readonly MarketplaceCatalogueRecordPresenter $recordPresenter,
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

    /** @return array<int, ExtensionListingData> */
    public function browseExtensions(): array
    {
        $downloadedComposerNames = $this->localStateSnapshot()->downloadedComposerNames;
        $result = FetchMarketplaceCataloguePageAction::run(
            query: new MarketplaceCatalogueQueryData(
                sort: MarketplaceClient::DEFAULT_EXTENSION_SORT,
                installedComposerNames: $downloadedComposerNames,
                page: 1,
                perPage: 9,
            ),
            allowStale: true,
        );

        if ($result->isUnavailable()) {
            return [];
        }

        return array_values(array_filter(
            $result->page->extensions,
            fn (ExtensionListingData $extension): bool => ! $this->isHiddenMarketplaceExtension($extension)
                && ! in_array($extension->composerName, $downloadedComposerNames, true),
        ));
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

        $includeLocalExtensionState = $this->canExposeLocalExtensionState($includeLocalExtensionState);
        $records = $composerNames
            ->mapWithKeys(function (string $composerName) use ($includeLocalExtensionState): array {
                $record = Cache::get($this->reviewRecordCacheKey($composerName, $includeLocalExtensionState));

                return is_array($record) ? [$composerName => $record] : [];
            })
            ->all();
        $composerNames = $composerNames
            ->reject(fn (string $composerName): bool => array_key_exists($composerName, $records))
            ->values();

        if ($composerNames->isEmpty()) {
            return $records;
        }

        $compatibilityVersions = $this->detectedCompatibilityVersions();
        $kind = $this->lockedMarketplaceKind($lockedKind) ?? '';
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

                $records[$composerName] = $this->cacheReviewRecord(
                    $this->extensionTableRecord($extension, $includeLocalExtensionState),
                    $includeLocalExtensionState,
                );
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

                $records[$composerName] = $this->cacheReviewRecord(
                    $this->extensionTableRecord($extension, $includeLocalExtensionState),
                    $includeLocalExtensionState,
                );

                break;
            }
        }

        return $records;
    }

    /**
     * @param  list<string>  $composerNames
     * @return array<string, MarketplaceSelectionRecordData>
     */
    public function selectionRecordsByComposerNames(
        array $composerNames,
        ?string $lockedKind = null,
        bool $includeLocalExtensionState = true,
    ): array {
        return array_map(
            MarketplaceSelectionRecordData::fromPayload(...),
            $this->recordsByComposerNames(
                composerNames: $composerNames,
                lockedKind: $lockedKind,
                includeLocalExtensionState: $includeLocalExtensionState,
            ),
        );
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
            if ($requestedComposerName === '') {
                continue;
            }

            if ($normalizedComposerName === null) {
                continue;
            }

            if ($normalizedComposerName === '') {
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
            page: $this->normalizePage($page),
            perPage: $this->normalizeRecordsPerPage($perPage),
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
            installedComposerNames: $includeLocalExtensionState ? $this->localStateSnapshot()->downloadedComposerNames : [],
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
            installedComposerNames: $includeLocalExtensionState ? $this->localStateSnapshot()->downloadedComposerNames : [],
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

    /** @return array{capell: ?string, laravel: ?string, livewire: ?string, filament: ?string} */
    public function detectedCompatibilityVersions(): array
    {
        return [
            'capell' => CapellCore::getInstalledPrettyVersion('capell-app/capell')
                ?? CapellCore::getInstalledPrettyVersion('capell/core'),
            'laravel' => $this->installedPackagePrettyVersion('laravel/framework') ?? app()->version(),
            'livewire' => $this->installedPackagePrettyVersion('livewire/livewire'),
            'filament' => $this->installedPackagePrettyVersion('filament/filament'),
        ];
    }

    public function normalizePage(int|string $value): int
    {
        if (! is_numeric($value) || (int) $value < 1) {
            return 1;
        }

        return min((int) $value, self::MAX_REMOTE_PAGE);
    }

    public function normalizeRecordsPerPage(int|string $value): int
    {
        return is_numeric($value) && in_array((int) $value, self::TABLE_PAGE_OPTIONS, true)
            ? (int) $value
            : self::DEFAULT_TABLE_PAGE_OPTION;
    }

    public function lockedMarketplaceKind(?string $lockedKind): ?string
    {
        $kind = $this->validKind($lockedKind);

        return $kind !== '' ? $kind : null;
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

                $records[] = $this->cacheReviewRecord(
                    $this->extensionTableRecord($extension, $includeLocalExtensionState),
                    $includeLocalExtensionState,
                );
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
                ->map(fn (ExtensionListingData $extension): array => $this->cacheReviewRecord(
                    $this->extensionTableRecord($extension, $includeLocalExtensionState),
                    $includeLocalExtensionState,
                ))
                ->values()
                ->all(),
            'total' => max($visibleExtensions->count(), $marketplacePage->total - $hiddenExtensionsCount),
            'next_page_url' => $marketplacePage->nextPageUrl,
            'stale' => $marketplacePage->stale,
        ];
    }

    private function localStateSnapshot(): MarketplaceCatalogueLocalStateSnapshotData
    {
        return $this->localStateSnapshot ??= BuildMarketplaceCatalogueLocalStateSnapshotAction::run();
    }

    private function localStateFor(
        ExtensionListingData $extension,
        bool $includeLocalExtensionState = true,
    ): MarketplaceCatalogueLocalStateData {
        return ResolveMarketplaceCatalogueLocalStateAction::run(
            listing: $extension,
            snapshot: $includeLocalExtensionState
                ? $this->localStateSnapshot()
                : MarketplaceCatalogueLocalStateSnapshotData::withoutLocalState(),
            includeLocalState: $includeLocalExtensionState,
        );
    }

    private function isInstalled(ExtensionListingData $extension): bool
    {
        return $this->localStateFor($extension)->isInstalled;
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

    private function canExposeLocalExtensionState(bool $requested): bool
    {
        if (! $requested) {
            return false;
        }

        return auth()->user() !== null;
    }

    private function fetchMarketplaceExtensionPage(MarketplaceCatalogueQueryData $query, bool $allowStale): MarketplaceCataloguePageData
    {
        $result = FetchMarketplaceCataloguePageAction::run($query, $allowStale);
        $this->marketplaceBrowseUnavailable = $result->isUnavailable();
        $this->marketplaceBrowseUnavailableReason = $result->unavailableReason;

        return $result->page;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function cacheReviewRecord(array $record, bool $includeLocalExtensionState): array
    {
        $composerName = $record['composer_name'] ?? null;

        if (is_string($composerName) && $composerName !== '') {
            Cache::put(
                $this->reviewRecordCacheKey($composerName, $includeLocalExtensionState),
                $record,
                now()->addSeconds((int) config('capell-marketplace.marketplace.cache_ttl_seconds', 300)),
            );
        }

        return $record;
    }

    private function reviewRecordCacheKey(string $composerName, bool $includeLocalExtensionState): string
    {
        $instance = $this->instances->latest();

        return 'capell-marketplace.marketplace.review-record.' . hash('xxh3', implode('|', [
            $instance?->instance_id ?? 'unconnected',
            $instance?->account_id ?? 'anonymous',
            auth()->id() !== null ? (string) auth()->id() : 'guest',
            $includeLocalExtensionState ? 'local-state' : 'remote-only',
            $composerName,
        ]));
    }

    private function extensionTableRecord(ExtensionListingData $extension, bool $includeLocalExtensionState = true): array
    {
        return $this->recordPresenter->present(ProjectMarketplaceCatalogueRecordAction::run(
            listing: $extension,
            localState: $this->localStateFor($extension, $includeLocalExtensionState),
            instance: $this->instances->latest(),
        ));
    }

    private function filterValue(array $filters, string $filter, string $field = 'value'): ?string
    {
        $value = $filters[$filter][$field] ?? null;

        return is_scalar($value) && (string) $value !== ''
            ? (string) $value
            : null;
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

        $strings = [];

        foreach ($values as $value) {
            if (is_scalar($value) && (string) $value !== '') {
                $strings[] = (string) $value;
            }
        }

        return $strings;
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
}

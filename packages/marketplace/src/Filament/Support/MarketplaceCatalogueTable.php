<?php

declare(strict_types=1);

namespace Capell\Marketplace\Filament\Support;

use Capell\Core\Data\PackageData;
use Capell\Core\Facades\CapellCore;
use Capell\Marketplace\Actions\InstallMarketplaceExtensionAction;
use Capell\Marketplace\Data\ExtensionListingData;
use Capell\Marketplace\Data\MarketplaceCatalogueQueryData;
use Capell\Marketplace\Data\MarketplaceInstallActorData;
use Capell\Marketplace\Data\MarketplaceInstallRequestData;
use Capell\Marketplace\Enums\ExtensionKind;
use Capell\Marketplace\Enums\MarketplaceExtensionCapability;
use Capell\Marketplace\Enums\MarketplaceExtensionCategory;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Enums\MarketplaceInstallSource;
use Capell\Marketplace\Enums\MarketplaceInstallState;
use Capell\Marketplace\Enums\MarketplaceSort;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Capell\Marketplace\Services\MarketplaceClient;
use Composer\InstalledVersions;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Layout\View as LayoutView;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class MarketplaceCatalogueTable
{
    /** @var array<int, int> */
    private const TABLE_PAGE_OPTIONS = [18, 36, 72];

    private const int DEFAULT_TABLE_PAGE_OPTION = 18;

    private const array INTERNAL_MARKETPLACE_COMPOSER_NAMES = [
        'capell-app/installer',
        'capell-app/marketplace',
        'capell-app/plugins',
    ];

    private const int MAX_REMOTE_PAGE = 100;

    public function __construct(
        private readonly MarketplaceCatalogueRecordProvider $recordProvider,
        private readonly MarketplaceInstallActionPresenter $installActionPresenter,
    ) {}

    public function configure(
        Table $table,
        ?string $lockedKind = null,
        bool $includeLocalExtensionState = true,
        bool $forceAvailableOnly = false,
    ): Table {
        /** @var view-string $extensionCardView */
        $extensionCardView = 'capell-admin::filament.pages.extensions.extension-card';

        return $table
            ->records(fn (?string $search = null, ?array $filters = null, int|string $page = 1, int|string $recordsPerPage = self::DEFAULT_TABLE_PAGE_OPTION): LengthAwarePaginator => $this->paginatedRecords(
                search: $search,
                filters: $this->tableFilters($filters ?? [], $forceAvailableOnly),
                lockedKind: $lockedKind,
                page: $this->tablePageValue($page),
                perPage: $this->tableRecordsPerPageValue($recordsPerPage),
                includeLocalExtensionState: $includeLocalExtensionState,
            ))
            ->searchable()
            ->searchPlaceholder((string) __('capell-marketplace::marketplace.filters.search_placeholder'))
            ->searchDebounce('300ms')
            ->filters($this->getMarketplaceTableFilters($lockedKind), FiltersLayout::Dropdown)
            ->filtersFormWidth(Width::FiveExtraLarge)
            ->filtersFormMaxHeight('min(42rem, calc(100vh - 12rem))')
            ->filtersTriggerAction(fn (Action $action): Action => $action
                ->button()
                ->label((string) __('capell-marketplace::marketplace.filters.trigger_label'))
                ->icon(Heroicon::OutlinedFunnel)
                ->color('gray'))
            ->deferFilters(false)
            ->filtersFormColumns([
                'md' => 2,
            ])
            ->filtersFormSchema(fn (array $filters): array => $this->marketplaceFiltersFormSchema($filters, $lockedKind))
            ->columns([
                LayoutView::make($extensionCardView),
            ])
            ->contentGrid([
                'md' => 2,
                'xl' => 3,
            ])
            ->recordClasses('capell-extension-card-record')
            ->extraAttributes([
                'class' => 'capell-marketplace-catalogue [&_.fi-ta-ctn]:overflow-hidden [&_.fi-ta-ctn]:rounded-xl [&_.fi-ta-ctn]:border-0 [&_.fi-ta-ctn]:bg-transparent [&_.fi-ta-ctn]:shadow-none [&_.fi-ta-header]:gap-3 [&_.fi-ta-header]:px-1 [&_.fi-ta-header]:py-2 [&_.fi-ta-header-toolbar]:gap-2 [&_.fi-ta-search-field]:min-w-80 [&_.fi-ta-search-field]:flex-1 [&_.fi-ta-search-field_.fi-input-wrp]:rounded-lg [&_.fi-ta-search-field_.fi-input-wrp]:bg-white dark:[&_.fi-ta-search-field_.fi-input-wrp]:bg-gray-900 [&_.fi-ta-content-ctn]:border-0 [&_.fi-ta-content-ctn]:bg-transparent [&_.fi-ta-content-ctn]:p-1 [&_.fi-ta-content]:gap-4 [&_.fi-ta-filter-indicators]:rounded-lg [&_.fi-ta-filter-indicators]:bg-gray-50 [&_.fi-ta-filter-indicators]:px-3 [&_.fi-ta-filter-indicators]:py-2 dark:[&_.fi-ta-filter-indicators]:bg-white/[0.04] [&_.fi-ta-pagination]:mt-4 [&_.fi-ta-pagination]:rounded-lg [&_.fi-ta-pagination]:border-0 [&_.fi-ta-pagination]:bg-gray-50 dark:[&_.fi-ta-pagination]:bg-white/[0.04]',
            ])
            ->paginated(self::TABLE_PAGE_OPTIONS)
            ->defaultPaginationPageOption(self::DEFAULT_TABLE_PAGE_OPTION)
            ->emptyStateHeading(fn (): string => $this->marketplaceEmptyStateHeading())
            ->emptyStateDescription(fn (): string => $this->marketplaceEmptyStateDescription());
    }

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
        return $this->recordProvider->records(
            search: $search,
            filters: $filters,
            lockedKind: $lockedKind,
            includeLocalExtensionState: $includeLocalExtensionState,
        );
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
        return $this->recordProvider->recordsByComposerNames(
            composerNames: $composerNames,
            lockedKind: $lockedKind,
            includeLocalExtensionState: $includeLocalExtensionState,
        );
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
        return $this->recordProvider->paginatedRecords(
            search: $search,
            filters: $filters,
            lockedKind: $lockedKind,
            page: $this->tablePageValue($page),
            perPage: $this->tableRecordsPerPageValue($perPage),
            includeLocalExtensionState: $includeLocalExtensionState,
        );
    }

    /** @return array<int, ExtensionListingData> */
    public function getBrowseExtensions(): array
    {
        try {
            $marketplacePage = resolve(MarketplaceClient::class)->listExtensionPage(new MarketplaceCatalogueQueryData(
                sort: MarketplaceClient::DEFAULT_EXTENSION_SORT,
                installedComposerNames: $this->downloadedMarketplaceComposerNames(),
                page: 1,
                perPage: 9,
            ), allowStale: true);

            return array_values(array_filter(
                $marketplacePage->extensions,
                fn (ExtensionListingData $extension): bool => ! $this->isHiddenMarketplaceExtension($extension)
                    && ! in_array($extension->composerName, $this->downloadedMarketplaceComposerNames(), true),
            ));
        } catch (Throwable $throwable) {
            Log::warning('capell-marketplace: marketplace browse failed', ['error' => $throwable->getMessage()]);

            return [];
        }
    }

    public function queueDefaultWarm(?string $lockedKind = null, bool $includeLocalExtensionState = true): bool
    {
        return $this->recordProvider->queueDefaultWarm($lockedKind, $includeLocalExtensionState);
    }

    public function marketplaceBrowseUnavailable(): bool
    {
        return $this->recordProvider->marketplaceBrowseUnavailable();
    }

    public function marketplaceBrowseUnavailableReason(): ?string
    {
        return $this->recordProvider->marketplaceBrowseUnavailableReason();
    }

    public function marketplaceEmptyStateHeading(): string
    {
        if ($this->marketplaceBrowseUnavailable()) {
            return (string) __('capell-marketplace::marketplace.filters.unavailable_heading');
        }

        return (string) __('capell-marketplace::marketplace.filters.empty_heading');
    }

    public function marketplaceEmptyStateDescription(): string
    {
        if ($this->marketplaceBrowseUnavailable()) {
            $description = (string) __('capell-marketplace::marketplace.filters.unavailable_description');
            $reason = $this->marketplaceBrowseUnavailableReason();

            return is_string($reason) && $reason !== ''
                ? $description . ' ' . __('capell-marketplace::marketplace.filters.unavailable_reason', ['reason' => $reason])
                : $description;
        }

        return (string) __('capell-marketplace::marketplace.filters.empty');
    }

    /** @return array<string, string> */
    public function getKindOptions(): array
    {
        return collect(ExtensionKind::cases())
            ->mapWithKeys(fn (ExtensionKind $kind): array => [$kind->value => $kind->getLabel()])
            ->all();
    }

    /** @return array<string, string> */
    public function getSortOptions(): array
    {
        return collect(MarketplaceSort::cases())
            ->mapWithKeys(fn (MarketplaceSort $sort): array => [$sort->value => $sort->getLabel()])
            ->all();
    }

    /** @return array<string, string> */
    /** @return array<string, string> */
    public function getCategoryOptions(): array
    {
        return collect(MarketplaceExtensionCategory::cases())
            ->mapWithKeys(fn (MarketplaceExtensionCategory $category): array => [$category->value => $category->getLabel()])
            ->all();
    }

    /** @return array<string, string> */
    public function getCapabilityOptions(): array
    {
        return collect(MarketplaceExtensionCapability::cases())
            ->mapWithKeys(fn (MarketplaceExtensionCapability $capability): array => [$capability->value => $capability->getLabel()])
            ->all();
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

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $data
     */
    public function installExtension(array $arguments, array $data = [], bool $redirectAccountActions = false): ?string
    {
        $user = auth()->user();

        return InstallMarketplaceExtensionAction::run(MarketplaceInstallRequestData::make(
            extensionSlug: (string) ($arguments['slug'] ?? ''),
            options: [
                ...$data,
                'composer_name' => $arguments['composer_name'] ?? null,
                'install_eligibility_policy' => $arguments['install_eligibility_policy'] ?? null,
                '_redirect_account_actions' => $redirectAccountActions,
            ],
            actor: $user instanceof Authenticatable
                ? MarketplaceInstallActorData::fromAuthenticatable($user)
                : MarketplaceInstallActorData::system('marketplace-catalogue-table'),
            betaAcknowledged: data_get($data, 'install_options.beta_acknowledged') === true,
            source: MarketplaceInstallSource::TableHelper,
        ));
    }

    /** @param array<string, mixed> $record */
    public function marketplaceInstallState(array $record): MarketplaceInstallState
    {
        return $this->installActionPresenter->state($record);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, mixed>
     */
    public function marketplaceFiltersFormSchema(array $filters, ?string $lockedKind): array
    {
        $primaryFilterNames = array_values(array_filter([
            $this->lockedMarketplaceKind($lockedKind) === null ? 'kind' : null,
            'installed_status',
            'category',
            'sort',
        ], is_string(...)));

        $primaryFilters = array_values(array_filter(
            array_map(fn (string $filterName): mixed => $filters[$filterName] ?? null, $primaryFilterNames),
            fn (mixed $filter): bool => $filter !== null,
        ));

        $advancedFilters = array_values(array_filter(
            $filters,
            fn (mixed $filter, string $filterName): bool => ! in_array($filterName, $primaryFilterNames, true),
            ARRAY_FILTER_USE_BOTH,
        ));

        return [
            Section::make((string) __('capell-marketplace::marketplace.filters.heading'))
                ->schema($primaryFilters)
                ->columns([
                    'md' => 2,
                ])
                ->compact()
                ->columnSpanFull(),
            Section::make((string) __('capell-marketplace::marketplace.filters.more_filters'))
                ->schema($advancedFilters)
                ->columns([
                    'md' => 2,
                ])
                ->collapsed()
                ->compact()
                ->columnSpanFull(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function tableFilters(array $filters, bool $forceAvailableOnly): array
    {
        if (! $forceAvailableOnly) {
            return $filters;
        }

        return [
            ...$filters,
            'installed_status' => [
                'value' => false,
            ],
        ];
    }

    /** @return array<int, Filter|SelectFilter|TernaryFilter> */
    private function getMarketplaceTableFilters(?string $lockedKind = null): array
    {
        $compatibilityVersions = $this->detectedCompatibilityVersions();

        $filters = [
            'kind' => SelectFilter::make('kind')
                ->label((string) __('capell-marketplace::marketplace.filters.type_label'))
                ->placeholder((string) __('capell-marketplace::marketplace.filters.all_types'))
                ->options($this->getKindOptions())
                ->indicateUsing(fn (array $data): ?string => $this->selectedOptionIndicator(
                    label: (string) __('capell-marketplace::marketplace.filters.type_label'),
                    options: $this->getKindOptions(),
                    value: $data['value'] ?? null,
                )),
            'category' => SelectFilter::make('category')
                ->label((string) __('capell-marketplace::marketplace.filters.category_label'))
                ->placeholder((string) __('capell-marketplace::marketplace.filters.all_categories'))
                ->options($this->getCategoryOptions())
                ->indicateUsing(fn (array $data): ?string => $this->selectedOptionIndicator(
                    label: (string) __('capell-marketplace::marketplace.filters.category_label'),
                    options: $this->getCategoryOptions(),
                    value: $data['value'] ?? null,
                )),
            'author' => Filter::make('author')
                ->label((string) __('capell-marketplace::marketplace.filters.author_label'))
                ->schema([
                    Hidden::make('author_slug'),
                    TextInput::make('author')
                        ->label((string) __('capell-marketplace::marketplace.filters.author_label'))
                        ->placeholder((string) __('capell-marketplace::marketplace.filters.author_placeholder')),
                ])
                ->indicateUsing(fn (array $data): ?string => $this->filledTextIndicator(
                    label: (string) __('capell-marketplace::marketplace.filters.author_label'),
                    value: $data['author'] ?? $data['author_slug'] ?? null,
                )),
            'capability' => SelectFilter::make('capability')
                ->label((string) __('capell-marketplace::marketplace.filters.capability_label'))
                ->placeholder((string) __('capell-marketplace::marketplace.filters.all_capabilities'))
                ->options($this->getCapabilityOptions())
                ->multiple()
                ->indicateUsing(fn (array $data): array => $this->selectedOptionsIndicators(
                    label: (string) __('capell-marketplace::marketplace.filters.capability_label'),
                    options: $this->getCapabilityOptions(),
                    values: $data['values'] ?? [],
                )),
            'free_only' => Filter::make('free_only')
                ->label((string) __('capell-marketplace::marketplace.filters.free_only'))
                ->indicateUsing(fn (array $data): ?string => ($data['isActive'] ?? false) === true
                    ? (string) __('capell-marketplace::marketplace.filters.free_only')
                    : null),
            'price' => Filter::make('price')
                ->label((string) __('capell-marketplace::marketplace.filters.price_range'))
                ->columns(2)
                ->schema([
                    TextInput::make('price_min')
                        ->label((string) __('capell-marketplace::marketplace.filters.price_min'))
                        ->numeric()
                        ->prefix('$')
                        ->placeholder('0'),
                    TextInput::make('price_max')
                        ->label((string) __('capell-marketplace::marketplace.filters.price_max'))
                        ->numeric()
                        ->prefix('$')
                        ->placeholder('99'),
                ])
                ->indicateUsing(fn (array $data): array => $this->priceFilterIndicators($data)),
            'sort' => SelectFilter::make('sort')
                ->label((string) __('capell-marketplace::marketplace.filters.sort_label'))
                ->options($this->getSortOptions())
                ->default(MarketplaceClient::DEFAULT_EXTENSION_SORT)
                ->selectablePlaceholder(false)
                ->indicateUsing(fn (array $data): ?string => $this->selectedOptionIndicator(
                    label: (string) __('capell-marketplace::marketplace.filters.sort_label'),
                    options: $this->getSortOptions(),
                    value: $data['value'] ?? MarketplaceClient::DEFAULT_EXTENSION_SORT,
                )),
            'installed_status' => TernaryFilter::make('installed_status')
                ->label((string) __('capell-marketplace::marketplace.filters.installed_status'))
                ->placeholder((string) __('capell-marketplace::marketplace.filters.all_extensions'))
                ->trueLabel((string) __('capell-marketplace::marketplace.filters.installed'))
                ->falseLabel((string) __('capell-marketplace::marketplace.filters.not_installed'))
                ->default(false)
                ->indicateUsing(fn (array $data): ?string => $this->installedStatusIndicator($data['value'] ?? false)),
            'compatibility' => Filter::make('compatibility')
                ->label((string) __('capell-marketplace::marketplace.filters.compatibility'))
                ->columns(2)
                ->schema([
                    Select::make('capell_version')
                        ->label((string) __('capell-marketplace::marketplace.filters.capell_version'))
                        ->options($this->versionFilterOptions($compatibilityVersions['capell']))
                        ->default($compatibilityVersions['capell'])
                        ->searchable()
                        ->native(false),
                    Select::make('laravel_version')
                        ->label((string) __('capell-marketplace::marketplace.filters.laravel_version'))
                        ->options($this->versionFilterOptions($compatibilityVersions['laravel']))
                        ->default($compatibilityVersions['laravel'])
                        ->searchable()
                        ->native(false),
                    Select::make('filament_version')
                        ->label((string) __('capell-marketplace::marketplace.filters.filament_version'))
                        ->options($this->versionFilterOptions($compatibilityVersions['filament']))
                        ->default($compatibilityVersions['filament'])
                        ->searchable()
                        ->native(false),
                    Select::make('livewire_version')
                        ->label((string) __('capell-marketplace::marketplace.filters.livewire_version'))
                        ->options($this->versionFilterOptions($compatibilityVersions['livewire']))
                        ->default($compatibilityVersions['livewire'])
                        ->searchable()
                        ->native(false),
                ])
                ->indicateUsing(fn (array $data): array => $this->compatibilityFilterIndicators($data)),
        ];

        if ($this->lockedMarketplaceKind($lockedKind) !== null) {
            unset($filters['kind']);
        }

        return array_values($filters);
    }

    /**
     * @param  array<string, string>  $options
     */
    private function selectedOptionIndicator(string $label, array $options, mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $selectedValue = (string) $value;

        if (! array_key_exists($selectedValue, $options)) {
            return null;
        }

        return $label . ': ' . $options[$selectedValue];
    }

    private function installedStatusIndicator(mixed $value): ?string
    {
        return match ($value) {
            true => (string) __('capell-marketplace::marketplace.filters.installed_status_indicator', [
                'status' => (string) __('capell-marketplace::marketplace.filters.installed'),
            ]),
            false => (string) __('capell-marketplace::marketplace.filters.installed_status_indicator', [
                'status' => (string) __('capell-marketplace::marketplace.filters.not_installed'),
            ]),
            default => null,
        };
    }

    /**
     * @param  array<string, string>  $options
     * @return array<int, string>
     */
    private function selectedOptionsIndicators(string $label, array $options, mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return collect($values)
            ->filter(fn (mixed $value): bool => is_scalar($value) && array_key_exists((string) $value, $options))
            ->map(fn (mixed $value): string => $label . ': ' . $options[(string) $value])
            ->values()
            ->all();
    }

    private function filledTextIndicator(string $label, mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== ''
            ? $label . ': ' . $value
            : null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function priceFilterIndicators(array $data): array
    {
        $indicators = [];

        if (is_scalar($data['price_min'] ?? null) && (string) $data['price_min'] !== '') {
            $indicators['price_min'] = (string) __('capell-marketplace::marketplace.filters.price_min_indicator', [
                'price' => (string) $data['price_min'],
            ]);
        }

        if (is_scalar($data['price_max'] ?? null) && (string) $data['price_max'] !== '') {
            $indicators['price_max'] = (string) __('capell-marketplace::marketplace.filters.price_max_indicator', [
                'price' => (string) $data['price_max'],
            ]);
        }

        return $indicators;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function compatibilityFilterIndicators(array $data): array
    {
        $detectedCompatibilityVersions = $this->detectedCompatibilityVersions();
        $labels = [
            'capell_version' => (string) __('capell-marketplace::marketplace.filters.capell_version'),
            'laravel_version' => (string) __('capell-marketplace::marketplace.filters.laravel_version'),
            'filament_version' => (string) __('capell-marketplace::marketplace.filters.filament_version'),
            'livewire_version' => (string) __('capell-marketplace::marketplace.filters.livewire_version'),
        ];

        $indicators = [];

        foreach ($labels as $key => $label) {
            $value = $data[$key] ?? null;
            $detectedKey = Str::before($key, '_version');

            if (
                is_scalar($value)
                && (string) $value !== ''
                && (string) $value !== ($detectedCompatibilityVersions[$detectedKey] ?? null)
            ) {
                $indicators[$key] = $label . ': ' . $value;
            }
        }

        return $indicators;
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

    /** @return array<string, string> */
    private function versionFilterOptions(?string $version): array
    {
        return is_string($version) && $version !== ''
            ? [$version => $version]
            : [];
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

    private function isHiddenMarketplaceExtension(ExtensionListingData $extension): bool
    {
        return in_array($extension->composerName, $this->hiddenMarketplaceComposerNames(), true);
    }

    /** @return array<int, string> */
    private function hiddenMarketplaceComposerNames(): array
    {
        return CapellCore::getPackages()
            ->filter(fn (PackageData $package): bool => $package->isHiddenFromMarketplace())
            ->keys()
            ->merge(self::INTERNAL_MARKETPLACE_COMPOSER_NAMES)
            ->unique()
            ->values()
            ->all();
    }

    /** @return array<int, string> */
    private function downloadedMarketplaceComposerNames(): array
    {
        return CapellCore::getPackages()
            ->filter(fn (PackageData $package): bool => CapellCore::isPackageInstalled($package->name)
                || CapellCore::isPackageAvailable($package->name))
            ->keys()
            ->merge($this->activeMarketplaceInstallComposerNames())
            ->unique()
            ->values()
            ->all();
    }

    /** @return array<int, string> */
    private function activeMarketplaceInstallComposerNames(): array
    {
        return MarketplaceInstallAttempt::query()
            ->whereIn('status', [
                MarketplaceInstallIntentStatus::Queued->value,
                MarketplaceInstallIntentStatus::Running->value,
                MarketplaceInstallIntentStatus::CancelRequested->value,
            ])
            ->pluck('composer_name')
            ->filter(fn (mixed $composerName): bool => is_string($composerName) && $composerName !== '')
            ->unique()
            ->values()
            ->all();
    }
}

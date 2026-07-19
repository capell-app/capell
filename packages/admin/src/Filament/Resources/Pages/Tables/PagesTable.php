<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Pages\Tables;

use Capell\Admin\Actions\Blueprints\UpdateBlueprintAction;
use Capell\Admin\Contracts\Extenders\PageTableExtender;
use Capell\Admin\Contracts\Pages\PageTableStatusResolver;
use Capell\Admin\Enums\FilamentColorEnum;
use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Filament\Actions\Table\ReplicatePageAction;
use Capell\Admin\Filament\Components\Tables\Actions\EditAction;
use Capell\Admin\Filament\Components\Tables\Actions\VisitUrlAction;
use Capell\Admin\Filament\Components\Tables\Columns\BlueprintColumn;
use Capell\Admin\Filament\Components\Tables\Columns\DateColumn;
use Capell\Admin\Filament\Components\Tables\Columns\IdentifierColumn;
use Capell\Admin\Filament\Components\Tables\Columns\LanguagesColumn;
use Capell\Admin\Filament\Components\Tables\Columns\Page\PagePublishStatusColumn;
use Capell\Admin\Filament\Components\Tables\Columns\Page\PageSummaryColumn;
use Capell\Admin\Filament\Components\Tables\Columns\SiteColumn;
use Capell\Admin\Filament\Components\Tables\Filters\DateFilter;
use Capell\Admin\Filament\Contracts\HasPageResource;
use Capell\Admin\Filament\Contracts\TableConfigurator;
use Capell\Admin\Filament\Contracts\ValidatesDelete;
use Capell\Admin\Filament\Resources\Blueprints\BlueprintResource;
use Capell\Admin\Filament\Resources\Layouts\LayoutResource;
use Capell\Admin\Filament\Resources\Pages\Actions\BulkMovePagesBulkAction;
use Capell\Admin\Filament\Resources\Pages\Actions\BulkPublishPagesBulkAction;
use Capell\Admin\Filament\Resources\Pages\Actions\BulkRevertToDraftBulkAction;
use Capell\Admin\Filament\Resources\Pages\Actions\BulkSchedulePagesBulkAction;
use Capell\Admin\Filament\Resources\Pages\Actions\ExportPagesBulkAction;
use Capell\Admin\Support\AdminSurfaceLookup;
use Capell\Admin\Support\Loader\SiteLoader;
use Capell\Admin\Support\PageUrlPresenter;
use Capell\Admin\Support\SiteScope;
use Capell\Core\Actions\GetEditPageResourceUrlAction;
use Capell\Core\Actions\PageDeletedAction;
use Capell\Core\Data\PageTypeData;
use Capell\Core\Enums\PublishVisibilityStateEnum;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page as PageModel;
use Capell\Core\Models\Site;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Pages\Page as ResourcePage;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\BaseFilter;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Support\LazyCollection;
use InvalidArgumentException;
use Livewire\Component;

/**
 * @template TLivewire of Component
 */
class PagesTable implements TableConfigurator
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(self::getTableQuery(...))
            ->defaultSort('updated_at', 'desc')
            ->columns(static::getTableColumns())
            ->filters(static::getTableFilters())
            ->filtersFormWidth('4xl')
            ->filtersFormColumns([
                'sm' => 2,
                'lg' => 3,
            ])
            ->filtersFormSchema(static::getFiltersFormSchema(...))
            ->columnManagerColumns(3)
            ->recordClasses(self::recordClasses(...))
            ->recordActions([
                EditAction::make(),
                ActionGroup::make([
                    VisitUrlAction::make(),
                    Action::make('edit-layout')
                        ->label(__('capell-admin::button.edit_layout'))
                        ->icon('heroicon-o-rectangle-group')
                        ->url(self::getLayoutRecordUrl(...))
                        ->hidden(fn (PageModel $record): bool => self::getLayoutRecordUrl($record) === null),
                    Action::make('edit-blueprint')
                        ->authorize(fn (): bool => auth()->user()?->can(ResourceEnum::Blueprint->permission('update')) === true)
                        ->schema(fn (Schema $schema, PageModel $record): Schema => BlueprintResource::form($schema->record($record->blueprint)))
                        ->fillForm(function (PageModel $record): array {
                            $data = $record->blueprint->attributesToArray();
                            $data['type'] = $record->blueprint->getRawOriginal('type');

                            return $data;
                        })
                        ->modalWidth(Width::ScreenLarge)
                        ->slideOver()
                        ->hidden(fn (PageModel $record): bool => ! $record->blueprint instanceof Blueprint || $record->blueprint->trashed())
                        ->modalHeading(
                            fn (PageModel $record): string => $record->blueprint->name,
                        )
                        ->mutateFormDataUsing(function (array $data, PageModel $record): array {
                            $data['type'] = self::blueprintTypeName($record->blueprint);

                            return $data;
                        })
                        ->action(function (PageModel $record, array $data): void {
                            $blueprint = $record->blueprint;

                            UpdateBlueprintAction::run($blueprint, $data);
                        }),
                    ReplicatePageAction::make(),
                    DeleteAction::make()
                        ->before(self::beforeRecordDelete(...))
                        ->after(self::afterRecordDeleted(...)),
                ])
                    ->color('gray'),
            ])
            ->toolbarActions([
                ExportPagesBulkAction::make(),
                BulkPublishPagesBulkAction::make(),
                BulkSchedulePagesBulkAction::make(),
                BulkRevertToDraftBulkAction::make(),
                BulkMovePagesBulkAction::make(),
                ...static::getExtenderBulkActions(),
                DeleteBulkAction::make()
                    ->before(self::beforeBulkDelete(...))
                    ->after(self::afterBulkDelete(...)),
                RestoreBulkAction::make(),
                ForceDeleteBulkAction::make()
                    ->after(self::afterRecordDeleted(...)),
            ])
            ->emptyStateHeading(__('capell-admin::generic.no_pages_found'))
            ->emptyStateDescription(__('capell-admin::generic.no_pages_description'))
            ->emptyStateIcon('heroicon-o-document-text')
            ->recordUrl(self::getRecordUrl(...));
    }

    /**
     * Base (core) filters definition for the Pages table.
     * Public to allow composition (e.g., other configurators can merge these with their own filters).
     *
     * @return list<BaseFilter>
     */
    public static function getBaseTableFilters(): array
    {
        return [
            SelectFilter::make('site_id')
                ->label(__('capell-admin::form.site'))
                ->default(self::currentSiteFilterDefault(...))
                ->searchable()
                ->relationship(
                    name: 'site',
                    titleAttribute: 'name',
                    modifyQueryUsing: self::applySiteFilterQuery(...),
                ),

            SelectFilter::make('blueprint_id')
                ->label(__('capell-admin::form.page_type'))
                ->searchable()
                ->relationship(
                    name: 'blueprint',
                    titleAttribute: 'name',
                    modifyQueryUsing: self::applyBlueprintFilterQuery(...),
                ),

            SelectFilter::make('layout_id')
                ->label(__('capell-admin::form.layout'))
                ->searchable()
                ->relationship(
                    name: 'layout',
                    titleAttribute: 'name',
                    modifyQueryUsing: self::applyLayoutFilterQuery(...),
                ),

            Filter::make('filter')
                ->schema([
                    Select::make('language_id')
                        ->label(__('capell-admin::table.language'))
                        ->searchable()
                        ->options(self::getLanguageOptions(...))
                        ->getSearchResultsUsing(self::getLanguageSearchResults(...)),
                ])
                ->query(self::applyFilterQuery(...))
                ->indicateUsing(self::indicateFilter(...)),

            DateFilter::make('visible_from')
                ->label(__('capell-admin::form.publish_date')),

            SelectFilter::make('publish_status')
                ->label(__('capell-admin::table.publish_status'))
                ->native(false)
                ->options(self::getPublishStatusFilterOptions())
                ->query(self::applyPublishStatusFilterQuery(...)),

            TrashedFilter::make()
                ->native(false),

            TernaryFilter::make('system_pages')
                ->label(__('capell-admin::form.system_pages'))
                ->trueLabel(__('capell-admin::generic.core_pages_all'))
                ->falseLabel(__('capell-admin::generic.core_pages_only'))
                ->placeholder(__('capell-admin::generic.core_pages_hide'))
                ->visible(self::shouldShowSystemPagesFilter(...))
                ->query(self::applySystemPagesFilterQuery(...))
                ->indicateUsing(self::indicateSystemPagesFilter(...)),
        ];
    }

    protected static function recordClasses(PageModel $record): ?string
    {
        return $record->deleted_at !== null ? 'table-row-warning' : null;
    }

    protected static function beforeRecordDelete(HasTable&ValidatesDelete $livewire, DeleteAction $action, PageModel $record): void
    {
        if (! $livewire->validateDelete($record)) {
            $action->cancel();
        }
    }

    protected static function afterRecordDeleted(PageModel $record): void
    {
        PageDeletedAction::run($record);
    }

    /**
     * @param  EloquentCollection<int, PageModel>|Collection<int, PageModel>|LazyCollection<int, PageModel>  $records
     */
    protected static function beforeBulkDelete(HasTable&ValidatesDelete $livewire, DeleteBulkAction $action, EloquentCollection|Collection|LazyCollection $records): void
    {
        $records->each(function (PageModel $record) use ($livewire, $action): void {
            if (! $livewire->validateDelete($record)) {
                $action->cancel();
            }
        });
    }

    /**
     * @param  Collection<int, PageModel>  $records
     */
    protected static function afterBulkDelete(DeleteBulkAction $action, Collection $records): void
    {
        $records->each(self::afterRecordDeleted(...));
    }

    protected static function getRecordUrl(PageModel $record): ?string
    {
        return GetEditPageResourceUrlAction::run($record);
    }

    /**
     * @param  Builder<Site>  $query
     * @return Builder<Site>
     */
    protected static function applySiteFilterQuery(Builder $query): Builder
    {
        return SiteScope::applyForCurrentActor($query, 'id')->ordered();
    }

    /**
     * The filter never offers "deleted" — the dedicated TrashedFilter owns
     * that axis — so only the four date-derived states appear as options.
     *
     * @return array<string, string>
     */
    protected static function getPublishStatusFilterOptions(): array
    {
        return [
            PublishVisibilityStateEnum::published->value => __('capell-admin::table.page_status_published'),
            PublishVisibilityStateEnum::draft->value => __('capell-admin::table.page_status_draft'),
            PublishVisibilityStateEnum::scheduled->value => __('capell-admin::table.page_status_scheduled'),
            PublishVisibilityStateEnum::expired->value => __('capell-admin::table.page_status_expired'),
        ];
    }

    /**
     * @param  Builder<PageModel>  $query
     * @param  array<string, mixed>  $data
     * @return Builder<PageModel>
     */
    protected static function applyPublishStatusFilterQuery(Builder $query, array $data): Builder
    {
        $value = is_string($data['value'] ?? null) ? $data['value'] : null;
        if ($value === null || $value === '') {
            return $query;
        }

        return match (PublishVisibilityStateEnum::tryFrom($value)) {
            PublishVisibilityStateEnum::draft => $query->draft(),
            PublishVisibilityStateEnum::scheduled => $query->scheduled(),
            PublishVisibilityStateEnum::expired => $query->expired(),
            PublishVisibilityStateEnum::published => $query->published(),
            default => $query,
        };
    }

    /**
     * @param  Builder<Blueprint>  $query
     * @return Builder<Blueprint>
     */
    protected static function applyBlueprintFilterQuery(Builder $query, ResourcePage|HasPageResource $livewire): Builder
    {
        return $query->enabled()
            ->pageType()
            ->adminResource($livewire::getResource()::getResourceName());
    }

    /**
     * @param  Builder<Layout>  $query
     * @return Builder<Layout>
     */
    protected static function applyLayoutFilterQuery(Builder $query): Builder
    {
        return $query
            ->whereIn($query->qualifyColumn($query->getModel()->getKeyName()), LayoutResource::getEloquentQuery()->select('id'))
            ->enabled()
            ->ordered();
    }

    /**
     * @return array<int, string>
     */
    protected static function getLanguageOptions(HasTable $livewire): array
    {
        if (! $livewire->isTableLoaded()) {
            return [];
        }

        return self::getLanguageSearchResults($livewire);
    }

    /**
     * @param  Builder<PageModel>  $query
     * @param  array<string, mixed>  $data
     */
    protected static function applyFilterQuery(Builder $query, array $data): void
    {
        $languageId = $data['language_id'] ?? null;
        if ($languageId === null || $languageId === '') {
            return;
        }

        $query->whereHas(
            'translations',
            fn (BuilderContract $query): BuilderContract => $query->where('language_id', (int) $languageId),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    protected static function indicateFilter(array $data): array
    {
        $indicators = [];

        if (($data['language_id'] ?? null) !== null && $data['language_id'] !== '') {
            /** @var class-string<Language> $model */
            $model = Language::class;

            $language = $model::query()->find($data['language_id']);

            if (! $language instanceof Language) {
                return $indicators;
            }

            $indicators['language_id'] = __(
                'capell-admin::filter.language',
                ['search' => $language->name],
            );
        }

        return $indicators;
    }

    protected static function shouldShowSystemPagesFilter(ResourcePage|HasPageResource $livewire): bool
    {
        return $livewire::getResource()::getResourceName() === 'default';
    }

    /**
     * @param  Builder<PageModel>  $query
     * @return Builder<PageModel>
     */
    protected static function applySystemPagesFilterQuery(Builder $query): Builder
    {
        return $query;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    protected static function indicateSystemPagesFilter(array $state): ?string
    {
        $value = $state['value'] ?? null;

        if ($value === null) {
            return null;
        }

        if ((bool) $value === false) {
            return __('capell-admin::generic.core_pages_only');
        }

        return __('capell-admin::generic.core_pages_all');
    }

    /**
     * @param  Builder<PageModel>  $query
     * @return Builder<PageModel>
     */
    protected static function getTableQuery(Builder $query, HasTable $livewire): Builder
    {
        $filterState = $livewire->getTableFilterState('filter');
        $languageId = is_array($filterState) ? ($filterState['language_id'] ?? null) : null;
        $relations = [
            'ancestors',
            'layout',
            'site' => self::includeTrashedSite(...),
            'translation' => fn (BuilderContract $query): BuilderContract => $query->with('language')
                ->select(['translatable_id', 'translatable_type', 'language_id', 'title'])
                ->when($languageId, self::applyTranslationLanguageFilter(...)),
            'blueprint',
            'pageUrls' => self::includeOrderedPageUrls(...),
            'pageUrl.siteDomain',
        ];

        if (self::isTableColumnVisible($livewire, 'parent.name')) {
            $relations['parent'] = self::includeParentType(...);
        }

        if (self::isTableColumnVisible($livewire, 'translations.language')) {
            $relations[] = 'translations.language';
        }

        if (self::isTableColumnVisible($livewire, 'creator.name')) {
            $relations[] = 'creator';
        }

        if (self::isTableColumnVisible($livewire, 'editor.name')) {
            $relations[] = 'editor';
        }

        return $query
            ->whereHas('site', self::includeTrashedSite(...))
            ->whereHas(
                'blueprint',
                function (BuilderContract $query) use ($livewire): void {
                    self::applyTypeResourceConstraint($query, $livewire);
                },
            )
            ->with($relations)
            ->withCount([
                'children',
            ])
            ->tap(fn (Builder $query): Builder => resolve(PageTableStatusResolver::class)->modifyQuery($query));
    }

    protected static function isTableColumnVisible(HasTable $livewire, string $column): bool
    {
        return ! $livewire->isTableColumnToggledHidden($column);
    }

    protected static function includeTrashedSite(BuilderContract $query): BuilderContract
    {
        return $query->withTrashed();
    }

    protected static function applyTypeResourceConstraint(BuilderContract $query, HasTable $livewire): void
    {
        $systemPages = $livewire->getTableFilterState('system_pages')['value'] ?? null;
        if ($systemPages !== null) {
            $systemPages = (bool) $systemPages;
        }

        throw_if(! $livewire instanceof ResourcePage && ! $livewire instanceof HasPageResource, InvalidArgumentException::class, $livewire::class . ' must be an instance of \Filament\Resources\Pages and HasPageResource');

        $livewire::getResource()::applyTypeAdminResourceConstraint(
            $query,
            $systemPages,
        );
    }

    protected static function includeParentType(BuilderContract $query): BuilderContract
    {
        if ($query instanceof Builder) {
            foreach (app()->tagged(PageTableExtender::TAG) as $extender) {
                if ($extender instanceof PageTableExtender) {
                    $query = $extender->modifyQuery($query);
                }
            }

            $query->with('blueprint');
        }

        return $query;
    }

    protected static function applyTranslationLanguageFilter(BuilderContract $query, int $languageId): BuilderContract
    {
        return $query->where('language_id', $languageId);
    }

    protected static function includeOrderedPageUrls(BuilderContract $query): BuilderContract
    {
        return $query->with('siteDomain')->ordered();
    }

    /** @return list<Column> */
    protected static function getTableColumns(): array
    {
        /** @var list<Column> $columns */
        $columns = [
            IdentifierColumn::make('id'),
            PageSummaryColumn::make('name')
                ->wrap()
                ->sortable()
                ->searchable(query: self::applyNameSearch(...))
                ->toggleable(),
            PagePublishStatusColumn::make('publish_status'),
            DateColumn::make('updated_at'),
            TextColumn::make('translation.title')
                ->label(__('capell-admin::table.title'))
                ->html()
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('parent.name')
                ->label(__('capell-admin::table.parent'))
                ->sortable()
                ->limit(60)
                ->toggleable(isToggledHiddenByDefault: true)
                ->url(self::getParentRecordUrl(...)),
            SiteColumn::make('site.name')
                ->color(FilamentColorEnum::LightGray->value)
                ->hidden(self::shouldHideSiteColumn(...)),
            TextColumn::make('url')
                ->label(__('capell-admin::table.url'))
                ->color('primary')
                ->disabledClick()
                ->html()
                ->searchable(query: self::applyUrlSearch(...))
                ->getStateUsing(self::getUrlColumnState(...))
                ->toggleable(isToggledHiddenByDefault: true),
            LanguagesColumn::make('translations.language'),
            TextColumn::make('layout.name')
                ->label(__('capell-admin::table.layout'))
                ->sortable()
                ->limit(30)
                ->size('sm')
                ->color(FilamentColorEnum::LightGray->value)
                ->toggleable(isToggledHiddenByDefault: true)
                ->url(self::getLayoutRecordUrl(...))
                ->width(0),
            BlueprintColumn::make('blueprint.name')
                ->label(__('capell-admin::table.page_type')),
            TextColumn::make('children_count')
                ->label(__('capell-admin::table.total_children'))
                ->alignCenter()
                ->numeric()
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('creator.name')
                ->label(__('capell-admin::table.created_by'))
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('editor.name')
                ->label(__('capell-admin::table.updated_by'))
                ->toggleable(isToggledHiddenByDefault: true),
            DateColumn::make('created_at'),
            DateColumn::make('deleted_at'),
        ];

        return array_merge($columns, static::getExtenderColumns());
    }

    protected static function getParentRecordUrl(PageModel $record): ?string
    {
        return $record->parent !== null
            ? GetEditPageResourceUrlAction::run($record->parent)
            : null;
    }

    protected static function blueprintTypeName(Blueprint $record): string
    {
        $type = $record->getRawOriginal('type');

        if (is_string($type) && $type !== '') {
            return $type;
        }

        $type = $record->getAttribute('type');

        return $type instanceof PageTypeData ? $type->name : '';
    }

    protected static function getLayoutRecordUrl(PageModel $record): ?string
    {
        return $record->layout instanceof Layout && auth()->user()?->can('update', $record->layout) === true
            ? AdminSurfaceLookup::resource(ResourceEnum::Layout)::getUrl('edit', ['record' => $record->layout])
            : null;
    }

    protected static function shouldHideSiteColumn(HasTable $livewire): bool
    {
        return (($livewire instanceof ListRecords && filled($livewire->activeTab))
                && ! in_array($livewire->getTableFilterState('site_id'), [null, []], true))
            || SiteLoader::getTotalSites() <= 1;
    }

    protected static function currentSiteFilterDefault(): ?int
    {
        $siteId = request()->integer('site') ?: (int) session()->get('capell.current_site_id', 0);

        return $siteId > 0 ? $siteId : null;
    }

    protected static function getUrlColumnState(PageModel $record, HasTable $livewire): ?HtmlString
    {
        $pageUrl = null;
        $languageId = $livewire->getTableFilterState('filter')['language_id'] ?? null;
        if ($languageId !== null && $languageId !== '') {
            $pageUrl = $record->pageUrls->firstWhere('language_id', $languageId);
        }

        if ($pageUrl === null) {
            $pageUrl = $record->pageUrls->first();
        }

        if ($pageUrl === null) {
            return null;
        }

        $displayUrl = e(PageUrlPresenter::displayUrl($pageUrl));
        $fullUrl = PageUrlPresenter::fullUrl($pageUrl);

        if ($fullUrl === null) {
            return new HtmlString($displayUrl);
        }

        $fullUrl = e($fullUrl);

        return new HtmlString('<a href="' . $fullUrl . '" target="_blank">' . $displayUrl . '</a>');
    }

    /**
     * @param  Builder<PageModel>  $query
     * @return Builder<PageModel>
     */
    protected static function applyNameSearch(Builder $query, string $search): Builder
    {
        return $query->where('name', 'like', sprintf('%%%s%%', $search))
            ->orWhereHas(
                'translations',
                fn (BuilderContract $query): BuilderContract => $query->where('title', 'like', sprintf('%%%s%%', $search)),
            )
            ->orderByRaw("CAST(IFNULL(NULLIF(POSITION(? IN pages.name), 0), 'void') AS UNSIGNED)", [$search]);
    }

    /**
     * @param  Builder<PageModel>  $query
     * @return Builder<PageModel>
     */
    protected static function applyUrlSearch(Builder $query, string $search): Builder
    {
        return $query->whereHas(
            'pageUrl',
            fn (BuilderContract $query): BuilderContract => $query->where('url', 'like', sprintf('%%%s%%', $search))
                ->orWhereHas(
                    'site',
                    fn (BuilderContract $query): BuilderContract => $query->whereHas(
                        'siteDomain',
                        fn (BuilderContract $query): BuilderContract => self::applyFullUrlSearch($query, $search),
                    ),
                )
                ->orWhereHas(
                    'pageable',
                    fn (BuilderContract $query): BuilderContract => $query->where('name', 'like', sprintf('%%%s%%', $search)),
                ),
        );
    }

    protected static function applyFullUrlSearch(BuilderContract $query, string $search): BuilderContract
    {
        $bindings = [
            config('capell-frontend.default_scheme', request()->getScheme()),
            parse_url((string) config('app.url'), PHP_URL_HOST),
            sprintf('%%%s%%', $search),
        ];

        $query->whereColumn('site_domains.language_id', 'page_urls.language_id');

        if (DB::getDriverName() === 'sqlite') {
            return $query->whereRaw(
                "COALESCE(site_domains.scheme, ?) || '://' || COALESCE(site_domains.domain, ?) || COALESCE(site_domains.path, '') || page_urls.url like ?",
                $bindings,
            );
        }

        return $query->whereRaw(
            "CONCAT(COALESCE(site_domains.scheme, ?), '://', COALESCE(site_domains.domain, ?), COALESCE(site_domains.path, ''), page_urls.url) like ?",
            $bindings,
        );
    }

    /**
     * @return array<int, Column>
     */
    protected static function getExtenderColumns(): array
    {
        return collect(app()->tagged(PageTableExtender::TAG))
            ->flatMap(fn (PageTableExtender $extender): array => $extender->getColumns())
            ->values()
            ->all();
    }

    /**
     * @return array<int, BulkAction>
     */
    protected static function getExtenderBulkActions(): array
    {
        return collect(app()->tagged(PageTableExtender::TAG))
            ->flatMap(fn (PageTableExtender $extender): array => $extender->getBulkActions())
            ->values()
            ->all();
    }

    /**
     * Returns the final set of table filters.
     *
     * Subclasses should normally override mutateTableFilters() instead of this method
     * to add, remove or alter filters without duplicating the whole definition.
     *
     * @return list<BaseFilter>
     */
    protected static function getTableFilters(): array
    {
        return static::mutateTableFilters([
            ...static::getBaseTableFilters(),
            ...static::getExtenderFilters(),
        ]);
    }

    /**
     * @return list<BaseFilter>
     */
    protected static function getExtenderFilters(): array
    {
        $filters = collect(app()->tagged(PageTableExtender::TAG))
            ->flatMap(fn (PageTableExtender $extender): array => $extender->getFilters())
            ->values()
            ->all();

        return array_values($filters);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<mixed>
     */
    protected static function getFiltersFormSchema(array $filters): array
    {
        $orderedFilterNames = [
            'site_id',
            'blueprint_id',
            'filter',
            'layout_id',
            'visible_from',
            'trashed',
            'system_pages',
            'page_speed_status',
        ];

        return [
            ...static::filtersForNames($filters, $orderedFilterNames),
            ...static::remainingFilters($filters, $orderedFilterNames),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  list<string>  $names
     * @return list<mixed>
     */
    protected static function filtersForNames(array $filters, array $names): array
    {
        return array_values(collect($names)
            ->map(fn (string $name): mixed => $filters[$name] ?? null)
            ->filter()
            ->values()
            ->all());
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  list<string>  $handledNames
     * @return list<mixed>
     */
    protected static function remainingFilters(array $filters, array $handledNames): array
    {
        return array_values(collect($filters)
            ->except($handledNames)
            ->values()
            ->all());
    }

    /**
     * Hook to allow subclasses to mutate (add/remove/reorder) filters.
     *
     * Example usage in a child class:
     * protected static function mutateTableFilters(array $filters): array {
     *     $filters[] = SelectFilter::make('my_extra')->label('Extra');
     *     return $filters;
     * }
     *
     * @param  list<BaseFilter>  $filters
     * @return list<BaseFilter>
     */
    protected static function mutateTableFilters(array $filters): array
    {
        return $filters; // Default: no changes.
    }

    /**
     * @return array<int, string>
     */
    protected static function getLanguageSearchResults(HasTable $livewire, ?string $search = null): array
    {
        /** @var class-string<Language> $model */
        $model = Language::class;

        $activeTabSiteId = $livewire instanceof ListRecords ? $livewire->activeTab : null;

        $query = $model::query();

        if ($activeTabSiteId !== null) {
            $query->whereHas(
                'sites',
                fn (BuilderContract $query): BuilderContract => $query->where('sites.id', $activeTabSiteId),
            );
        }

        if ($search !== null && $search !== '') {
            $query
                ->where('name', 'like', sprintf('%%%s%%', $search))
                ->orWhere('code', 'like', sprintf('%%%s%%', $search));
        }

        return $query
            ->ordered()
            ->get()
            ->pluck('name', 'id')
            ->all();
    }
}

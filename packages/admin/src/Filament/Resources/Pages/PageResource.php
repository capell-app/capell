<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Pages;

use BackedEnum;
use Capell\Admin\Contracts\Extenders\PageResourcePageExtender;
use Capell\Admin\Contracts\Extenders\PageResourceWidgetExtender;
use Capell\Admin\Contracts\Extenders\PageTableExtender;
use Capell\Admin\Enums\ConfiguratorTypeEnum;
use Capell\Admin\Filament\Concerns\HasConfiguredForm;
use Capell\Admin\Filament\Concerns\HasConfiguredTable;
use Capell\Admin\Filament\Concerns\HasNavigationBadge;
use Capell\Admin\Filament\Concerns\Validate\PageValidation;
use Capell\Admin\Filament\Contracts\ValidatesDelete;
use Capell\Admin\Filament\RelationManagers\ActivityHistoryRelationManager;
use Capell\Admin\Filament\RelationManagers\EventSourcedHistoryRelationManager;
use Capell\Admin\Filament\Resources\Pages\Pages\CreatePage;
use Capell\Admin\Filament\Resources\Pages\Pages\EditPage;
use Capell\Admin\Filament\Resources\Pages\Pages\ListPages;
use Capell\Admin\Filament\Resources\Pages\RelationManagers\ChildrenRelationManager;
use Capell\Admin\Filament\Resources\Pages\RelationManagers\SiblingsRelationManager;
use Capell\Admin\Filament\Resources\Pages\RelationManagers\UrlsRelationManager;
use Capell\Admin\Filament\Resources\Pages\Schemas\PageForm;
use Capell\Admin\Filament\Resources\Pages\Tables\PagesTable;
use Capell\Admin\Filament\Resources\Pages\Widgets\ListPageAlertsWidget;
use Capell\Admin\Policies\PagePolicy;
use Capell\Admin\Support\Search\AppliesNameSearchRelevance;
use Capell\Admin\Support\SiteScope;
use Capell\Core\Actions\GetNameFromTranslationsAction;
use Capell\Core\Enums\BlueprintGroupEnum;
use Capell\Core\Enums\BlueprintSubjectEnum;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Filament\Widgets\Widget;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Override;

class PageResource extends Resource implements ValidatesDelete
{
    use AppliesNameSearchRelevance;
    use HasConfiguredForm;
    use HasConfiguredTable;
    use HasNavigationBadge;
    use PageValidation;

    protected static string $adminResourceName = 'default';

    protected static ?int $navigationSort = -80;

    protected static ?string $recordTitleAttribute = 'name';

    protected static bool $isGloballySearchable = true;

    protected static string $tableConfigurator = PagesTable::class;

    protected static string $formConfigurator = PageForm::class;

    /** @return class-string<PagePolicy> */
    public static function getPolicy(): ?string
    {
        return PagePolicy::class;
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return static::configuredTable($table, ConfiguratorTypeEnum::Page);
    }

    #[Override]
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ])
            ->whereHas('blueprint', static::applyBaseTypeAdminResourceConstraint(...));

        SiteScope::applyForCurrentActor($query);

        return collect(app()->tagged(PageTableExtender::TAG))
            ->reduce(fn (Builder $carry, PageTableExtender $extender): Builder => $extender->modifyQuery($carry), $query);
    }

    public static function hasPageHierarchy(): bool
    {
        return static::getModel()::hasPageHierarchy();
    }

    #[Override]
    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'translations.title', 'translations.meta->slug', 'pageUrls.url'];
    }

    #[Override]
    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return SiteScope::applyForCurrentActor(parent::getGlobalSearchEloquentQuery())
            ->with([
                'site:id,name,default',
                'translation',
                'blueprint:id,name',
                'ancestors',
            ]);
    }

    /**
     * @param  Page  $record
     * @return array<int, Htmlable|string>
     */
    #[Override]
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        $details = [];

        $title = $record->title;

        if (is_string($title) && $title !== '' && $title !== $record->name) {
            $details[] = $title;
        }

        $breadcrumb = self::buildGlobalSearchBreadcrumbs($record);

        if ($breadcrumb instanceof HtmlString) {
            $details[] = $breadcrumb;
        }

        return $details;
    }

    /**
     * @return class-string<Page>
     */
    #[Override]
    public static function getModel(): string
    {
        return Page::class;
    }

    #[Override]
    public static function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return Heroicon::OutlinedGlobeAlt;
    }

    #[Override]
    public static function getActiveNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return Heroicon::GlobeAlt;
    }

    #[Override]
    public static function getNavigationGroup(): ?string
    {
        return (string) __('capell-admin::navigation.group_websites');
    }

    #[Override]
    public static function getNavigationLabel(): string
    {
        return (string) __('capell-admin::navigation.pages');
    }

    #[Override]
    public static function getPages(): array
    {
        $pages = [
            'index' => ListPages::route('/'),
            'create' => CreatePage::route('/create'),
            'edit' => EditPage::route('/{record}/edit'),
        ];

        foreach (app()->tagged(PageResourcePageExtender::TAG) as $extender) {
            /** @var PageResourcePageExtender $extender */
            $pages = array_merge($pages, $extender->getPages());
        }

        return $pages;
    }

    #[Override]
    public static function getPluralModelLabel(): string
    {
        return __('capell-admin::generic.pages');
    }

    #[Override]
    public static function getRelations(): array
    {
        return [
            ActivityHistoryRelationManager::class,
            EventSourcedHistoryRelationManager::class,
            UrlsRelationManager::class,
            ChildrenRelationManager::class,
            SiblingsRelationManager::class,
        ];
    }

    public static function getResourceName(): string
    {
        return strtolower(static::$adminResourceName);
    }

    #[Override]
    public static function getWidgets(): array
    {
        /** @var list<class-string<Widget>> $widgets */
        $widgets = [ListPageAlertsWidget::class];

        foreach (app()->tagged(PageResourceWidgetExtender::TAG) as $extender) {
            if (! $extender instanceof PageResourceWidgetExtender) {
                continue;
            }

            foreach ($extender->getWidgets() as $widget) {
                if (is_string($widget) && is_subclass_of($widget, Widget::class)) {
                    $widgets[] = $widget;
                }
            }
        }

        return $widgets;
    }

    /** @param Builder<Page> $query */
    public static function modifyGlobalSearchQuery(Builder $query, string $search): void
    {
        parent::modifyGlobalSearchQuery($query, $search);

        $query->withWhereHas(
            'blueprint',
            function (BuilderContract $query): void {
                static::applyTypeAdminResourceConstraint($query);
            },
        );

        static::applyNameSearchRelevance($query, $search);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $formData
     */
    public static function mutateFormDataBeforeCreate(array &$data, array $formData = []): void
    {
        $siteId = $data['site_id'] ?? null;

        /** @var class-string<Site> $model */
        $model = Site::class;

        $site = is_scalar($siteId) ? $model::query()->find($siteId) : null;
        if (! $site instanceof Site) {
            $site = $model::query()->default()->first();
        }

        if ($site === null) {
            return;
        }

        if (! array_key_exists('site_id', $data) || $data['site_id'] === '') {
            $data['site_id'] = $site->id;
        }

        if (! array_key_exists('layout_id', $data) || $data['layout_id'] === '') {
            /** @var class-string<Layout> $model */
            $model = Layout::class;

            $data['layout_id'] = $model::query()->default()->value('id');
        }

        if (! array_key_exists('blueprint_id', $data) || $data['blueprint_id'] === '') {
            $group = static::getResourceName();

            /** @var class-string<Blueprint> $model */
            $model = Blueprint::class;

            $typeId = $model::query()->pageType()->default()->adminResource($group)->value('id');

            if ($typeId !== null) {
                $data['blueprint_id'] = $typeId;
            }
        }

        $hasName = isset($data['name']) && $data['name'] !== '';
        $hasTranslations = isset($formData['translations']) && is_array($formData['translations']) && $formData['translations'] !== [];
        if (! $hasName && $hasTranslations) {
            $data['name'] = GetNameFromTranslationsAction::run(collect($formData['translations']), $site);
        }
    }

    public static function applyTypeAdminResourceConstraint(BuilderContract $query, ?bool $systemPages = null): void
    {
        static::applyBaseTypeAdminResourceConstraint($query);

        if (static::getResourceName() !== 'default') {
            return;
        }

        if ($systemPages === true) {
            return;
        }

        if ($systemPages === false) {
            $query->where('group', BlueprintGroupEnum::System->value);

            return;
        }

        $query->where(
            fn (Builder $query) => $query
                ->where($query->qualifyColumn('group'), '!=', BlueprintGroupEnum::System->value)
                ->orWhereNull($query->qualifyColumn('group')),
        );
    }

    public static function applyBaseTypeAdminResourceConstraint(BuilderContract $query): void
    {
        $query->where('type', BlueprintSubjectEnum::Page->value);

        if (static::getResourceName() !== 'default') {
            $query->adminResource(static::getResourceName());
        }
    }

    /** @param Builder<Page> $query */
    #[Override]
    protected static function applyGlobalSearchAttributeConstraints(Builder $query, string $search): void
    {
        $query->where(function (Builder $query) use ($search): void {
            parent::applyGlobalSearchAttributeConstraints($query, $search);

            if (Str::contains($search, ['http://', 'https://'])) {
                static::applyGlobalSearchUrlConstraint($query, Str::after($search, '//'));
            }
        });
    }

    /** @param Builder<Page> $query */
    protected static function applyGlobalSearchUrlConstraint(Builder $query, string $search): void
    {
        $query->orWhereHas(
            'pageUrl',
            fn (BuilderContract $query): BuilderContract => $query->where('url', 'like', sprintf('%%%s%%', $search))
                ->orWhereHas(
                    'site',
                    fn (BuilderContract $query): BuilderContract => $query->whereHas(
                        'siteDomain',
                        fn (BuilderContract $query): BuilderContract => static::applyGlobalSearchFullUrlConstraint($query, $search),
                    ),
                ),
        );
    }

    protected static function applyGlobalSearchFullUrlConstraint(BuilderContract $query, string $search): BuilderContract
    {
        $bindings = [
            parse_url((string) config('app.url'), PHP_URL_HOST),
            sprintf('%%%s%%', $search),
        ];

        $query->whereColumn('site_domains.language_id', 'page_urls.language_id');

        if (DB::getDriverName() === 'sqlite') {
            return $query->whereRaw(
                "COALESCE(site_domains.domain, ?) || COALESCE(site_domains.path, '') || page_urls.url like ?",
                $bindings,
            );
        }

        return $query->whereRaw(
            "CONCAT(COALESCE(site_domains.domain, ?), COALESCE(site_domains.path, ''), page_urls.url) like ?",
            $bindings,
        );
    }

    private static function buildGlobalSearchBreadcrumbs(Page $record): ?HtmlString
    {
        $breadcrumbs = [];

        if (! $record->site->default) {
            $breadcrumbs[] = e($record->site->name);
        }

        if ($record->ancestors->isNotEmpty()) {
            $breadcrumbs[] = $record->ancestors
                ->pluck('name')
                ->map(fn (string $name): string => e($name))
                ->implode(' &raquo; ');
        }

        if (filled($breadcrumbs)) {
            return new HtmlString(implode(' &raquo; ', $breadcrumbs));
        }

        return null;
    }
}

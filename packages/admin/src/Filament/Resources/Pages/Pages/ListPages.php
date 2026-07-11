<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Pages\Pages;

use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Filament\Actions\Page\CreatePageAction;
use Capell\Admin\Filament\Concerns\ApplySearchRelationsTable;
use Capell\Admin\Filament\Concerns\HasImportExportHeaderActions;
use Capell\Admin\Filament\Concerns\HasSiteTableFilterTabs;
use Capell\Admin\Filament\Concerns\Validate\PageValidation;
use Capell\Admin\Filament\Contracts\HasPageResource;
use Capell\Admin\Filament\Contracts\ValidatesDelete;
use Capell\Admin\Filament\Resources\Pages\PageResource;
use Capell\Admin\Support\AdminSurfaceLookup;
use Capell\Admin\Support\Schemas\AdminSchemaExtensionPipeline;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Override;

class ListPages extends ListRecords implements HasPageResource, ValidatesDelete
{
    use ApplySearchRelationsTable;
    use HasImportExportHeaderActions;
    use HasSiteTableFilterTabs;
    use PageValidation;

    protected string $siteRelation = 'pages';

    /** @return class-string<PageResource> */
    #[Override]
    public static function getResource(): string
    {
        /** @var class-string<PageResource> $resource */
        $resource = AdminSurfaceLookup::resource(ResourceEnum::Page);

        return $resource;
    }

    /** @return Builder<Page> */
    public function getFilteredTableQuery(): Builder
    {
        $query = parent::getFilteredTableQuery();

        if (! $query instanceof Builder) {
            $query = Page::query();
        }

        $hasLanguageFilter = isset($this->getTableFilterState('filter')['language_id']) && $this->getTableFilterState('filter')['language_id'] !== '';

        if ($hasLanguageFilter) {
            $language_id = $this->getTableFilterState('filter')['language_id'];
        } else {
            /** @var class-string<Language> $model */
            $model = Language::class;

            $language_id = $model::query()->default()->value('id');
        }

        $query->with([
            'translation' => fn (BuilderContract $query): BuilderContract => $this->applyLanguageFilter($query, $hasLanguageFilter, $language_id),
            'pageUrl' => fn (BuilderContract $query): BuilderContract => $this->applyLanguageFilter($query, $hasLanguageFilter, $language_id)
                ->with('siteDomain'),
        ]);

        return $query;
    }

    #[Override]
    public function getSubheading(): string|Htmlable|null
    {
        return __('capell-admin::hints.pages_list');
    }

    #[Override]
    public function getHeaderWidgetsColumns(): int|array
    {
        return ['default' => 1];
    }

    protected function shouldCacheSiteTableFilterTabs(): bool
    {
        return false;
    }

    /**
     * @param  Builder<Site>  $query
     * @return Builder<Site>
     */
    protected function modifySiteTableFilterTabsQuery(Builder $query): Builder
    {
        return $query;
    }

    /** @param Builder<Page> $query */
    protected function modifySiteTabRelationCountQuery(Builder $query, Model $related): void
    {
        $systemPages = $this->getTableFilterState('system_pages')['value'] ?? null;
        if ($systemPages !== null) {
            $systemPages = (bool) $systemPages;
        }

        $query->whereHas(
            'blueprint',
            function (BuilderContract $query) use ($systemPages): void {
                PageResource::applyTypeAdminResourceConstraint(
                    $query,
                    $systemPages,
                );
            },
        );
    }

    #[Override]
    protected function getHeaderWidgets(): array
    {
        $resource = static::getResource();

        return $resource::getWidgets();
    }

    #[Override]
    protected function getActions(): array
    {
        $canCreate = static::getResource()::canCreate();

        return $this->prependImportHeaderAction([
            $this->choosePageTypeAction()->visible($canCreate),
            CreatePageAction::make()
                ->label(__('capell-admin::button.quick_create_default_page'))
                ->tooltip(__('capell-admin::button.quick_create_default_page_tooltip'))
                ->color('gray')
                ->visible($canCreate),
            ...resolve(AdminSchemaExtensionPipeline::class)->resourceHeaderActions(static::class),
        ]);
    }

    protected function getSearchRelationColumns(): array
    {
        return [
            'translations' => [
                'content',
                'title',
                'meta->label',
                'meta->title',
                'meta->slug',
                'meta->summary',
            ],
            'pageUrls' => ['url'],
        ];
    }

    protected function hasNoSitesFilterTab(): bool
    {
        return false;
    }

    private function choosePageTypeAction(): Action
    {
        return Action::make('choosePageType')
            ->label(__('capell-admin::button.new_page'))
            ->icon('heroicon-o-document-plus')
            ->modalHeading(__('capell-admin::generic.page_type_chooser_heading'))
            ->modalDescription(__('capell-admin::generic.page_type_chooser_description'))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('capell-admin::button.close'))
            ->modalWidth(Width::FourExtraLarge)
            ->modalContent(fn (): HtmlString => new HtmlString(view(
                'capell-admin::filament.resources.pages.page-type-chooser',
                ['pageTypes' => $this->pageTypeChoices()],
            )->render()));
    }

    /**
     * @return Collection<int, Blueprint>
     */
    private function pageTypeChoices(): Collection
    {
        $resource = static::getResource();

        return Blueprint::query()
            ->enabled()
            ->pageType()
            ->adminResource($resource::getResourceName())
            ->withCount('pages')
            ->ordered()
            ->get()
            ->each(fn (Blueprint $pageType): Blueprint => $pageType->setAttribute(
                'create_url',
                $resource::getUrl('create', ['type' => $pageType->key]),
            ));
    }

    private function applyLanguageFilter(BuilderContract $query, bool $hasLanguageFilter, ?int $language_id): BuilderContract
    {
        if ($language_id === null || $language_id === 0) {
            return $query;
        }

        return $query->when(
            $hasLanguageFilter,
            fn (BuilderContract $query): BuilderContract => $query->where('language_id', $language_id),
            fn (BuilderContract $query): BuilderContract => $query->when(
                DB::getDriverName() === 'sqlite',
                fn (BuilderContract $query): BuilderContract => $query->orderByRaw('CASE WHEN language_id = ? THEN 0 ELSE 1 END', [$language_id]),
                fn (BuilderContract $query): BuilderContract => $query->orderByRaw('FIELD(language_id, ?) DESC', [$language_id]),
            ),
        );
    }
}

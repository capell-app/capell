<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Pages\Pages;

use Capell\Admin\Actions\Pages\BuildPageListingQueryAction;
use Capell\Admin\Actions\Pages\QuickCreatePageAction;
use Capell\Admin\Actions\Pages\ResolvePageCreationSiteAction;
use Capell\Admin\Actions\Pages\ResolvePageJourneyLanguageAction;
use Capell\Admin\Data\Pages\QuickCreatePageData;
use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Filament\Concerns\ApplySearchRelationsTable;
use Capell\Admin\Filament\Concerns\HasImportExportHeaderActions;
use Capell\Admin\Filament\Concerns\HasSiteTableFilterTabs;
use Capell\Admin\Filament\Concerns\Validate\PageValidation;
use Capell\Admin\Filament\Contracts\HasPageResource;
use Capell\Admin\Filament\Contracts\ValidatesDelete;
use Capell\Admin\Filament\Resources\Pages\PageResource;
use Capell\Admin\Support\AdminSurfaceLookup;
use Capell\Admin\Support\Schemas\AdminSchemaExtensionPipeline;
use Capell\Core\Actions\GetEditPageResourceUrlAction;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Support\Permissions\SiteAccess;
use Capell\Core\Support\Slug\SlugGenerator;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
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
            $query = SiteAccess::current()->query(Page::class);
        }

        return BuildPageListingQueryAction::run(
            $query,
            $this->getTableFilterState('filter')['language_id'] ?? null,
        );
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
            $this->quickCreatePageAction()->visible($canCreate),
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

    private function quickCreatePageAction(): Action
    {
        return Action::make('create')
            ->label(__('capell-admin::button.new_page'))
            ->icon('heroicon-o-document-plus')
            ->slideOver()
            ->modalWidth(Width::Large)
            ->modalHeading(__('capell-admin::button.new_page'))
            ->modalDescription(__('capell-admin::generic.quick_create_page_description'))
            ->modalSubmitActionLabel(__('capell-admin::button.create'))
            ->fillForm(fn (): array => $this->quickCreateDefaults())
            ->schema(fn (): array => $this->quickCreateFields())
            ->action(function (array $data, Action $action): void {
                try {
                    $page = $this->quickCreatePage($data);
                } catch (ValidationException $validationException) {
                    Notification::make()
                        ->danger()
                        ->title(__('capell-admin::message.quick_create_page_failed'))
                        ->body(collect($validationException->errors())->flatten()->first())
                        ->send();

                    $action->halt();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title(__('capell-admin::message.page_created', ['title' => $page->name]))
                    ->actions([
                        Action::make('edit')
                            ->label(__('capell-admin::button.edit_page'))
                            ->url(GetEditPageResourceUrlAction::run($page)),
                    ])
                    ->send();

                $this->resetTable();
            });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function quickCreatePage(array $data): Page
    {
        return QuickCreatePageAction::run(new QuickCreatePageData(
            title: (string) $data['title'],
            slug: filled($data['slug'] ?? null) ? (string) $data['slug'] : null,
            siteId: (int) $data['site_id'],
            languageId: (int) $data['language_id'],
            blueprintId: filled($data['blueprint_id'] ?? null) ? (int) $data['blueprint_id'] : null,
            parentId: filled($data['parent_id'] ?? null) ? (int) $data['parent_id'] : null,
            publish: (bool) ($data['publish'] ?? false),
        ), static::getResource());
    }

    /**
     * @return array<string, mixed>
     */
    private function quickCreateDefaults(): array
    {
        $site = ResolvePageCreationSiteAction::run(
            is_numeric($this->activeTab) ? $this->activeTab : ($this->getTableFilterState('site_id')['value'] ?? null),
        );
        $language = ResolvePageJourneyLanguageAction::run($site?->id, $this->getTableFilterState('filter')['language_id'] ?? null);

        return [
            'site_id' => $site?->id,
            'language_id' => $language->id ?? $site?->language_id,
            'blueprint_id' => Page::getDefaultType(static::getResource()::getResourceName())?->getKey(),
            'publish' => true,
        ];
    }

    /**
     * @return array<int, Component>
     */
    private function quickCreateFields(): array
    {
        $sites = SiteAccess::current()->query(Site::class)->with('languages')->get();

        return [
            TextInput::make('title')
                ->label(__('capell-admin::form.title'))
                ->required()
                ->maxLength(255)
                ->autofocus()
                ->live(onBlur: true)
                ->afterStateUpdated(function (Get $get, Set $set, ?string $state, ?string $old): void {
                    $slug = (string) $get('slug');
                    if ($slug === '' || $slug === SlugGenerator::slug((string) $old)) {
                        $set('slug', SlugGenerator::slug((string) $state));
                    }
                }),
            TextInput::make('slug')
                ->label(__('capell-admin::form.slug'))
                ->helperText(__('capell-admin::hints.quick_create_page_slug'))
                ->maxLength(255),
            Select::make('blueprint_id')
                ->label(__('capell-admin::form.page_type'))
                ->options(fn (): array => $this->editorialPageTypeOptions())
                ->required()
                ->native(false),
            Select::make('parent_id')
                ->label(__('capell-admin::form.parent_page'))
                ->placeholder(__('capell-admin::form.no_parent_page'))
                ->searchable()
                ->getSearchResultsUsing(fn (Get $get, string $search): array => $this->parentPageOptions($get('site_id'), $search))
                ->getOptionLabelUsing(fn (Get $get, mixed $value): ?string => $this->parentPageOptions($get('site_id'), parentId: $value)[$value] ?? null)
                ->options(fn (Get $get): array => $this->parentPageOptions($get('site_id'))),
            Select::make('site_id')
                ->label(__('capell-admin::form.site'))
                ->options($sites->pluck('name', 'id')->all())
                ->required()
                ->live()
                ->afterStateUpdated(function (Set $set, mixed $state) use ($sites): void {
                    $set('language_id', $sites->firstWhere('id', $state)?->language_id);
                    $set('parent_id', null);
                })
                ->visible($sites->count() > 1)
                ->dehydratedWhenHidden(),
            Select::make('language_id')
                ->label(__('capell-admin::form.language'))
                ->options(fn (Get $get): array => $sites->firstWhere('id', $get('site_id'))?->languages->pluck('name', 'id')->all() ?? [])
                ->required()
                ->visible(fn (Get $get): bool => ($sites->firstWhere('id', $get('site_id'))?->languages->count() ?? 0) > 1)
                ->dehydratedWhenHidden(),
            Toggle::make('publish')
                ->label(__('capell-admin::form.publish_now'))
                ->helperText(__('capell-admin::hints.quick_create_page_publish')),
        ];
    }

    /**
     * Fixed system types (maintenance, not found, system) are provisioned, not created by editors.
     *
     * @return array<int|string, string>
     */
    private function editorialPageTypeOptions(): array
    {
        return Blueprint::query()
            ->enabled()
            ->pageType()
            ->adminResource(static::getResource()::getResourceName())
            ->hiddenSystemGroup()
            ->ordered()
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @return array<int|string, string>
     */
    private function parentPageOptions(mixed $siteId, ?string $search = null, mixed $parentId = null): array
    {
        if (! is_numeric($siteId)) {
            return [];
        }

        return SiteAccess::current()->query(Page::class)
            ->where('site_id', (int) $siteId)
            ->when(filled($search), fn (Builder $query): Builder => $query->where('name', 'like', '%' . $search . '%'))
            ->when($parentId !== null, fn (Builder $query): Builder => $query->whereKey($parentId))
            ->orderBy('name')
            ->limit(50)
            ->pluck('name', 'id')
            ->all();
    }
}

<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms;

use Aimeos\Nestedset\NestedSet;
use Capell\Admin\Contracts\Extenders\PageTableExtender;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Actions\HintEditAction;
use Capell\Admin\Filament\Concerns\HasCustomSelectOption;
use Capell\Admin\Filament\Resources\Pages\Schemas\PageForm;
use Capell\Admin\Support\SiteScope;
use Capell\Core\Actions\GetEditPageResourceUrlAction;
use Capell\Core\Contracts\Pageable;
use Capell\Core\Enums\AssetEnum;
use Capell\Core\Enums\BlueprintGroupEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class PageSelect extends Select
{
    use HasCustomSelectOption;

    protected null|string|Closure $pageGroup = null;

    protected null|string|Closure $pageType = null;

    protected ?string $parentPageType = null;

    private ?Closure $modifySelectOptionsQueryUsing = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('capell-admin::form.page'))
            ->searchable()
            ->allowHtml()
            ->getSearchResultsUsing(function (self $component, ?Model $record, Get $get, string $search): array {
                $site_id = $get('site_id') ?? ($record instanceof Site ? $record->id : null);

                return $component->getPageOptions(
                    site_id: $site_id,
                    search: $search,
                );
            })
            ->getOptionLabelUsing(fn (?int $value): ?string => SiteScope::applyForCurrentActor(Page::query())
                ->where('id', $value)
                ->value('name'))
            ->options(function (self $component, ?Model $record, Get $get): array {
                $site_id = $get('site_id') ?? ($record instanceof Site ? $record->id : null);

                return $component->getPageOptions(
                    site_id: $site_id,
                );
            });
    }

    public function getPageType(): ?string
    {
        return $this->evaluate($this->pageType);
    }

    public function modifySelectOptionsQueryUsing(?Closure $callback): static
    {
        $this->modifySelectOptionsQueryUsing = $callback;

        return $this;
    }

    public function pageType(string|Closure $pageType): static
    {
        $this->pageType = $pageType;

        return $this;
    }

    public function pageGroup(string|Closure $pageGroup): static
    {
        $this->pageGroup = $pageGroup;

        return $this;
    }

    public function parentPageType(string $pageType): static
    {
        $this->parentPageType = $pageType;

        return $this;
    }

    public function withCreateForm(): Select
    {
        $asset = CapellCore::getAsset(AssetEnum::Page);

        $adminAsset = CapellAdmin::getAsset(AssetEnum::Page);

        $createOptionUsing = $this->getCreateOptionUsing();

        return $this->createOptionAction(
            fn (Action $action): Action => $this->modifyCreateAction($action)
                ->fillForm(fn (): array => in_array($adminAsset->defaultDataAction, [null, '', '0'], true) ? [] : $adminAsset->defaultDataAction::run()),
        )
            ->createOptionForm(
                fn (Schema $schema): Schema => $adminAsset->formClass::configure(
                    $schema->operation('createOption')->model($this->assetModelClass($asset->model)),
                ),
            )
            ->createOptionUsing(function (Select $component, array $data) use ($asset, $adminAsset, $createOptionUsing): int|string {
                $page = in_array($adminAsset->createAction, [null, '', '0'], true)
                    ? $component->evaluate($createOptionUsing)
                    : $adminAsset->createAction::run($data);

                Notification::make()
                    ->title(__('capell-admin::message.asset_created_successfully', ['name' => $asset->name]))
                    ->body($page->name)
                    ->send();

                return $page->getKey();
            })
            ->getOptionLabelFromRecordUsing(fn (Model $record): string => $record instanceof Pageable ? static::getSelectOption($record) : '');
    }

    public function withEditForm(): self
    {
        return $this->editOptionForm(function (?int $state, Schema $schema): ?Schema {
            if ($state === null || $state === 0) {
                return null;
            }

            return PageForm::configure($schema->operation('editOption'));
        })
            ->editOptionAction(
                fn (Action $action): Action => $action
                    ->modalHeading(function (string $context, self $component, ?int $state): ?HtmlString {
                        if ($state === null || $state === 0) {
                            return null;
                        }

                        $selectedRecord = $component->getSelectedRecord();

                        if (! $selectedRecord instanceof Pageable) {
                            return null;
                        }

                        $title = Str::title($selectedRecord->blueprint->name);

                        return new HtmlString(__('capell-admin::heading.edit_page_record', ['name' => $title]));
                    })
                    ->modalWidth(Width::ScreenLarge)
                    ->visible(fn (?int $state): bool => (bool) $state)
                    ->successNotificationTitle(
                        fn (Action $action): string => __(
                            'capell-admin::notification.updated_successfully',
                            ['name' => $this->modalHeadingText($action)],
                        ),
                    )
                    ->after(function (Action $action): void {
                        $action->success();
                    }),
            )
            ->fillEditOptionActionFormUsing(static function (self $component): array {
                $record = $component->getSelectedRecord();

                return $record?->attributesToArray() ?? [];
            })
            ->getSelectedRecordUsing(
                static fn (Select $component, ?int $state): ?Model => SiteScope::applyForCurrentActor(Page::query())->find($state),
            );
    }

    public function withHintEditAction(): static
    {
        return $this->hintAction(
            HintEditAction::make('edit-page')
                ->visible(fn (null|string|int $state, string $operation): bool => $operation !== 'create' && filled($state))
                ->url(function (null|string|int $state): ?string {
                    if ($state === 0 || ($state === '' || $state === '0') || $state === null) {
                        return null;
                    }

                    /** @var class-string<Page> $model */
                    $model = Page::class;

                    /** @var ?Page $page */
                    $page = $model::query()->withWhereHas('blueprint:id,admin')->find($state);

                    if ($page === null) {
                        return null;
                    }

                    return GetEditPageResourceUrlAction::run($page);
                }),
        );
    }

    /**
     * @return array<int|string, string>
     */
    protected function getPageOptions(?int $site_id = null, ?string $search = null): array
    {
        $relations = ['ancestors' => fn (BuilderContract $query): BuilderContract => $query instanceof Builder
            ? $this->applyPageTableExtenders($query)
            : $query];

        if ($site_id === null || $site_id === 0) {
            $relations[] = 'site';
        }

        $pageType = $this->getPageType();

        $pageGroup = $this->pageGroup;

        $parentPageType = $this->parentPageType;

        /** @var class-string<Page> $model */
        $model = Page::class;

        $query = SiteScope::applyForCurrentActor($model::query())->select([
            'pages.id',
            'pages.name',
            'pages.site_id',
            'pages.parent_id',
            'pages._lft',
            'pages._rgt',
        ])
            ->when(
                $this->modifySelectOptionsQueryUsing,
                fn (Builder $query): mixed => $this->evaluate($this->modifySelectOptionsQueryUsing, ['query' => $query]),
            )
            ->whereHas(
                'blueprint',
                function (BuilderContract $query) use ($pageGroup, $pageType): BuilderContract {
                    $query->where(
                        fn (BuilderContract $query): BuilderContract => $query->whereNot('group', BlueprintGroupEnum::System->value)
                            ->orWhereNull('group'),
                    );

                    if ($pageGroup !== null) {
                        $this->applyPageGroupConstraint($query, $pageGroup);
                    }

                    if ($pageType !== null) {
                        $query->where('key', $pageType);
                    }

                    return $query;
                },
            )
            ->when(
                $site_id,
                fn (Builder $query): Builder => $query->where('site_id', $site_id),
            )
            ->when(
                $parentPageType,
                fn (Builder $query): Builder => $query->whereHas(
                    'parent.blueprint',
                    fn (BuilderContract $query): BuilderContract => $query->where('key', $parentPageType),
                ),
            )
            ->when(
                $search,
                fn (Builder $query, string $search): Builder => $query->where('pages.name', 'like', sprintf('%%%s%%', $search))
                    ->orderByRaw(
                        'CASE WHEN pages.name = ? THEN 1 ELSE 0 END DESC, INSTR(pages.name, ?), pages.name',
                        [$search, $search],
                    ),
                fn (Builder $query): Builder => $query->limit(10),
            );

        /** @var Builder<Model> $query */
        $query = $this->applyPageTableExtenders($query);

        $pages = $query
            ->with($relations)
            ->orderBy('site_id')
            ->orderBy(NestedSet::LFT, 'asc')
            ->get();

        $limit = $this->getOptionsLimit();

        $total = $query->count();

        if ($total > $limit) {
            $pages->pop();
            $this->disableOptionWhen(fn (string $value): bool => $value === '' || $value === '0');
        }

        $options = [];

        /** @var Collection<int, Page> $pages */
        $pages->each(function (Page $page) use (&$options, $site_id): void {
            $label = '';

            if ($site_id === null || $site_id === 0) {
                $label .= $page->site->name . ' » ';
            }

            if ($page->ancestors->isNotEmpty()) {
                $label .= $page->ancestors->pluck('name')
                    ->map(fn (string $item): string => Str::limit($item, 30))
                    ->implode(' » ')
                    . ' » ';
            }

            $label .= Str::limit($page->name, 40);

            $options[$page->getKey()] = $label;
        });

        if ($total > $limit) {
            $options[''] = __('capell-admin::form.more_results', ['count' => $total - $limit]);
        }

        return $options;
    }

    private function modalHeadingText(Action $action): string
    {
        $heading = $action->getModalHeading();

        return $heading instanceof Htmlable ? $heading->toHtml() : $heading;
    }

    private function applyPageGroupConstraint(BuilderContract $query, string|Closure $pageGroup): BuilderContract
    {
        if (is_string($pageGroup)) {
            return $query->adminResource($pageGroup);
        }

        $this->evaluate($pageGroup, ['query' => $query]);

        return $query;
    }

    private function modifyCreateAction(Action $action): Action
    {
        return $action->slideOver()
            ->modalWidth(Width::ScreenLarge)
            ->closeModalByClickingAway(false)
            ->successNotificationTitle(
                fn (Action $action): string => __(
                    'capell-admin::notification.created_successfully',
                    ['name' => $this->modalHeadingText($action)],
                ),
            )
            ->after(function (Action $action): void {
                $action->success();
            });
    }

    /**
     * @return class-string<Model>|null
     */
    private function assetModelClass(mixed $model): ?string
    {
        return is_string($model) && is_subclass_of($model, Model::class) ? $model : null;
    }

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    private function applyPageTableExtenders(Builder $query): Builder
    {
        foreach (app()->tagged(PageTableExtender::TAG) as $extender) {
            if ($extender instanceof PageTableExtender) {
                $query = $extender->modifyQuery($query);
            }
        }

        return $query;
    }
}

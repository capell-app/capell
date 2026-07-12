<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Layouts\Tables;

use Capell\Admin\Actions\ReplicateLayoutAction;
use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Filament\Components\Tables\Actions\EditAction;
use Capell\Admin\Filament\Components\Tables\Actions\ReplicateAction;
use Capell\Admin\Filament\Components\Tables\Columns\DateColumn;
use Capell\Admin\Filament\Components\Tables\Columns\IdentifierColumn;
use Capell\Admin\Filament\Components\Tables\Columns\ImageColumn;
use Capell\Admin\Filament\Components\Tables\Columns\NameColumn;
use Capell\Admin\Filament\Components\Tables\Columns\SiteColumn;
use Capell\Admin\Filament\Components\Tables\Columns\StatusIconColumn;
use Capell\Admin\Filament\Contracts\TableConfigurator;
use Capell\Admin\Filament\Resources\Layouts\Pages\ListLayouts;
use Capell\Admin\Filament\Resources\Sites\SiteResource;
use Capell\Admin\Filament\Resources\Themes\Tables\ThemesTable;
use Capell\Admin\Filament\Resources\Themes\ThemeResource;
use Capell\Admin\Support\AdminSurfaceLookup;
use Capell\Admin\Support\SiteScope;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\Layout\View;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Support\LazyCollection;

class LayoutsTable implements TableConfigurator
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => static::getTableQueryModifier($query))
            ->defaultSort('name')
            ->defaultSortOptionLabel(__('capell-admin::table.name'))
            ->columns(static::getTableColumns())
            ->contentGrid([
                'md' => 2,
                'xl' => 3,
            ])
            ->filters(static::getTableFilters())
            ->recordClasses(fn (Layout $record): string => match (true) {
                (bool) $record->deleted_at => 'capell-layout-card-record table-row-warning',
                default => 'capell-layout-card-record',
            })
            ->recordActions(static::getTableActions())
            ->toolbarActions([
                RestoreBulkAction::make(),
                ForceDeleteBulkAction::make(),
                DeleteBulkAction::make()
                    ->before(function (ListLayouts $livewire, DeleteBulkAction $action, EloquentCollection|Collection|LazyCollection $records): void {
                        $records->each(function (Layout $record) use ($livewire, $action): void {
                            if (! $livewire->validateDelete($record)) {
                                $action->cancel();
                            }
                        });
                    }),
            ])
            ->emptyStateHeading(__('capell-admin::generic.no_layouts'))
            ->emptyStateDescription(__('capell-admin::generic.no_layouts_description'))
            ->emptyStateIcon('heroicon-o-rectangle-group');
    }

    /**
     * @param  Builder<Layout>  $query
     * @return Builder<Layout>
     */
    protected static function getTableQueryModifier(Builder $query): Builder
    {
        return $query->with([
            'creator',
            'editor',
            'image',
            'site',
            'theme',
        ])
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ])
            ->select([
                'layouts.*',
                self::getUsesCountSelect($query, 'pages_count'),
            ]);
    }

    /** @param Builder<Layout> $query */
    protected static function getUsesCountSelect(Builder $query, ?string $alias = null): ExpressionContract
    {
        $baseTable = $query->getModel()->getTable();

        $pageVariations = CapellCore::getPageVariationModels();

        $parts = array_map(function (string $pageClass) use ($baseTable): string {
            // Instantiate to resolve table name; avoid calling static methods on unknown classes
            $pageModel = new $pageClass;
            $pageTable = $pageModel->getTable();

            // count rows in the page table that reference this layout id
            return sprintf(
                'COALESCE((SELECT COUNT(*) FROM %s WHERE %s.layout_id = %s.id%s), 0)',
                DB::getTablePrefix() . $pageTable,
                DB::getTablePrefix() . $pageTable,
                DB::getTablePrefix() . $baseTable,
                self::getPageSiteSqlConstraint(DB::getTablePrefix() . $pageTable),
            );
        }, $pageVariations);

        $expression = '0';

        if ($parts !== []) {
            $expression = '(' . implode(' + ', $parts) . ')';
        }

        if ($alias !== null) {
            $expression = sprintf('%s AS %s', $expression, $alias);
        }

        /** @var literal-string $expression */
        return DB::raw($expression);
    }

    /** @return array<int, mixed> */
    protected static function getTableActions(): array
    {
        return [
            EditAction::make(),
            ActionGroup::make([
                Action::make('edit-site')
                    ->label(__('capell-admin::button.edit_site'))
                    ->icon('heroicon-o-building-storefront')
                    ->url(self::getSiteRecordUrl(...))
                    ->hidden(fn (Layout $record): bool => self::getSiteRecordUrl($record) === null),
                Action::make('edit-theme')
                    ->label(__('capell-admin::button.edit_theme'))
                    ->icon('heroicon-o-swatch')
                    ->authorize(fn (): bool => auth()->user()?->can(ResourceEnum::Theme->permission('update')) === true)
                    ->schema(fn (Schema $schema): Schema => ThemeResource::form($schema))
                    ->fillForm(fn (Layout $record): array => $record->theme?->attributesToArray() ?? [])
                    ->modalHeading(fn (Layout $record): string => $record->theme->name ?? '')
                    ->slideOver()
                    ->modalWidth(Width::ScreenLarge)
                    ->mutateFormDataUsing(fn (array $data, Layout $record): array => $record->theme instanceof Theme
                        ? ThemesTable::editorRecordData($record->theme, $data)
                        : $data)
                    ->action(function (Layout $record, array $data): void {
                        $record->theme?->update($data);
                    })
                    ->hidden(fn (Layout $record): bool => ! $record->theme instanceof Theme || $record->theme->trashed()),
                ReplicateAction::make()
                    ->replicaModelAction(ReplicateLayoutAction::class),
                DeleteAction::make()
                    ->before(function (ListLayouts $livewire, Layout $record, DeleteAction $action): void {
                        if (! $livewire->validateDelete($record)) {
                            $action->cancel();
                        }
                    }),
            ])
                ->color('gray'),
        ];
    }

    protected static function getSiteRecordUrl(Layout $record): ?string
    {
        return $record->site instanceof Site && auth()->user()?->can('update', $record->site) === true
            ? SiteResource::getUrl('edit', ['record' => $record->site])
            : null;
    }

    /** @return array<int, mixed> */
    protected static function getTableColumns(): array
    {
        /** @var view-string $layoutCardView */
        $layoutCardView = 'capell-admin::filament.resources.layouts.layout-card';

        return [
            View::make($layoutCardView),
            IdentifierColumn::make('id')
                ->hidden(),
            NameColumn::make('name')
                ->searchable(['name', 'key'])
                ->formatStateUsing(fn (): string => ''),
            ImageColumn::make('admin.image')
                ->visibility('public')
                ->hidden(),
            SiteColumn::make('site.name')
                ->hidden(),
            TextColumn::make('theme.name')
                ->label(__('capell-admin::table.theme'))
                ->sortable()
                ->limit(30)
                ->hidden(),
            TextColumn::make('pages_count')
                ->label(__('capell-admin::table.total_pages'))
                ->alignCenter()
                ->sortable(query: self::applyPagesCountSort(...))
                ->numeric()
                ->disabledClick()
                ->toggleable()
                ->formatStateUsing(
                    function (Layout $record, int $state): ?HtmlString {
                        if ($state === 0) {
                            return null;
                        }

                        $urls = [];

                        foreach (CapellCore::getPageVariations() as $pageVariation) {
                            $model = Relation::getMorphedModel($pageVariation->model) ?? $pageVariation->model;

                            $count = SiteScope::applyForCurrentActor($model::query())
                                ->where('layout_id', $record->id)
                                ->count();

                            if ($count > 0) {
                                /** @var class-string<resource> $resource */
                                $resource = AdminSurfaceLookup::resource(ResourceEnum::Page, $pageVariation->resourceName);

                                $url = $resource::getUrl(
                                    'index',
                                    [
                                        'filters[layout_id][value]' => $record->id,
                                        'filters[system_pages][value]' => '1',
                                    ],
                                );

                                $urls[] = [
                                    'url' => $url,
                                    'label' => __('capell-admin::table.layout_pages_of_type', ['type' => $pageVariation->name, 'count' => $count]),
                                ];
                            }
                        }

                        return new HtmlString(Blade::render('capell-admin::components.tables.urls', [
                            'urls' => $urls,
                        ]));
                    },
                )
                ->hidden(),
            StatusIconColumn::make('status')
                ->hidden(),
            DateColumn::make('created_at')
                ->hidden(),
            DateColumn::make('updated_at')
                ->hidden(),
            DateColumn::make('deleted_at')
                ->hidden(),
        ];
    }

    /**
     * @param  Builder<Layout>  $query
     * @return Builder<Layout>
     */
    protected static function applyPagesCountSort(Builder $query, string $direction): Builder
    {
        $sortDirection = $direction === 'asc' ? 'asc' : 'desc';

        return $query->orderBy(
            self::getUsesCountSelect($query),
            $sortDirection,
        );
    }

    /** @return array<int, mixed> */
    protected static function getTableFilters(): array
    {
        return [
            SelectFilter::make('site_id')
                ->label(__('capell-admin::form.site'))
                ->relationship(
                    name: 'site',
                    titleAttribute: 'name',
                    modifyQueryUsing: fn (Builder $query): Builder => SiteScope::applyForCurrentActor($query, 'id')->ordered(),
                ),
            SelectFilter::make('theme_id')
                ->label(__('capell-admin::form.theme'))
                ->relationship(name: 'theme', titleAttribute: 'name'),
            TrashedFilter::make(),
        ];
    }

    private static function getPageSiteSqlConstraint(string $qualifiedPageTable): string
    {
        $actor = auth()->user();

        if (! $actor instanceof Authenticatable || SiteScope::isGlobalActor($actor)) {
            return '';
        }

        $assignedSiteIds = $actor->getAssignedSiteIds()->values();

        if ($assignedSiteIds->isEmpty()) {
            return ' AND 1 = 0';
        }

        return sprintf(
            ' AND %s.site_id IN (%s)',
            $qualifiedPageTable,
            $assignedSiteIds->implode(','),
        );
    }
}

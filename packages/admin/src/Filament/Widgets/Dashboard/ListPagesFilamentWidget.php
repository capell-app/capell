<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Widgets\Dashboard;

use Capell\Admin\Contracts\CapellFilamentWidgetContract;
use Capell\Admin\Filament\Components\Tables\Columns\DateColumn;
use Capell\Admin\Filament\Components\Tables\Columns\Page\PageNameColumn;
use Capell\Admin\Filament\Concerns\GatedByRoleAndSettings;
use Capell\Admin\Filament\Concerns\HasDashboardDateRange;
use Capell\Admin\Filament\Resources\Pages\PageResource;
use Capell\Admin\Filament\Resources\Sites\SiteResource;
use Capell\Admin\Support\Loader\SiteLoader;
use Capell\Admin\Support\SiteScope;
use Capell\Core\Actions\GetEditPageResourceUrlAction;
use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Support\Enums\Alignment;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Override;

class ListPagesFilamentWidget extends BaseWidget implements CapellFilamentWidgetContract
{
    use GatedByRoleAndSettings;
    use HasDashboardDateRange;

    /** @var list<string> */
    protected static array $rolesConfigKeys = [];

    protected static string $settingsKey = 'list_pages';

    protected int|string|array $columnSpan = [
        'default' => 'full',
    ];

    protected static ?int $sort = 10;

    /**
     * @return Builder<Page>
     */
    public function getFilteredTableQuery(): Builder
    {
        $query = parent::getFilteredTableQuery();

        if (! $query instanceof Builder) {
            $query = Page::query();
        }

        $languageId = $this->getTableFilterState('filter')['language_id'] ?? null;
        if ($languageId === null || $languageId === '') {
            return $query;
        }

        return $query->with([
            'translations' => fn (BuilderContract $query): BuilderContract => $query->when(
                DB::getDriverName() === 'sqlite',
                fn (BuilderContract $query): BuilderContract => $query->orderByRaw('CASE WHEN language_id = ? THEN 0 ELSE 1 END', [$languageId]),
                fn (BuilderContract $query): BuilderContract => $query->orderByRaw('FIELD(language_id, ?) DESC', [$languageId]),
            ),
            'url' => fn (BuilderContract $query): BuilderContract => $query->when(
                DB::getDriverName() === 'sqlite',
                fn (BuilderContract $query): BuilderContract => $query->orderByRaw('CASE WHEN language_id = ? THEN 0 ELSE 1 END', [$languageId]),
                fn (BuilderContract $query): BuilderContract => $query->orderByRaw('FIELD(language_id, ?) DESC', [$languageId]),
            ),
        ]);
    }

    #[Override]
    public function table(Table $table): Table
    {
        return $table
            ->query(
                function (): Builder {
                    /** @var class-string<Page> $model */
                    $model = Page::class;

                    $query = SiteScope::applyForCurrentActor($model::query())
                        ->with([
                            'ancestors',
                            'site',
                            'type',
                            'pageUrl.siteDomain',
                        ]);

                    return $query->when(
                        $this->hasDashboardPeriodFilter(),
                        fn (Builder $query): Builder => $query->whereBetween(
                            $query->getModel()->qualifyColumn('updated_at'),
                            $this->getDashboardDateRange(),
                        ),
                    );
                },
            )
            ->searchable(false)
            ->heading($this->tableHeading())
            ->description($this->tableDescription())
            ->columns($this->tableColumns())
            ->queryStringIdentifier('latest-pages')
            ->filtersFormColumns(2)
            ->paginationPageOptions([5])
            ->headerActions([
                Action::make('view-all')
                    ->label(__('capell-admin::button.view_all'))
                    ->button()
                    ->color('gray')
                    ->url(PageResource::getUrl()),
            ])
            ->recordActions([
                EditAction::make()
                    ->iconButton()
                    ->url(fn (Pageable $record): ?string => GetEditPageResourceUrlAction::run($record)),
            ])
            ->tap(function (Table $table): Table {
                $sitesCount = Site::query()->count();

                if ($sitesCount === 0) {
                    return $table->emptyStateHeading(__('capell-admin::generic.no_sites'))
                        ->emptyStateDescription(__('capell-admin::generic.no_sites_description'))
                        ->emptyStateIcon('heroicon-o-globe-alt')
                        ->emptyStateActions([
                            Action::make('createSite')
                                ->label(__('capell-admin::button.create_site'))
                                ->link()
                                ->url(SiteResource::getUrl('create')),
                        ]);
                }

                return $table;
            });
    }

    /**
     * @return array<int, mixed>
     */
    protected function tableColumns(): array
    {
        return [
            Split::make([
                PageNameColumn::make('name')
                    ->withTypeIcon()
                    ->size(null)
                    ->toggleable(false)
                    ->ancestorsPrefix()
                    ->nameUrl(fn (Pageable $record): ?string => GetEditPageResourceUrlAction::run($record))
                    ->urlDescription(),
                Stack::make([
                    TextColumn::make('site.name')
                        ->label(__('capell-admin::table.site'))
                        ->visible(fn (): bool => SiteLoader::getTotalSites() > 1)
                        ->toggleable(false),
                    DateColumn::make('updated_at')
                        ->sortable(false)
                        ->toggleable(false)
                        ->since()
                        ->dateTimeTooltip(),
                ])
                    ->alignment(Alignment::End)
                    ->space(1)
                    ->grow(false),
            ]),
        ];
    }

    protected function tableHeading(): string
    {
        return __('capell-admin::heading.recently_updated_pages');
    }

    protected function tableDescription(): string
    {
        return __('capell-admin::dashboard.widget_list_pages_runtime_description', [
            'period' => __('capell-admin::dashboard.period_' . $this->getDashboardPeriod()),
        ]);
    }

    /**
     * @param  Builder<Page>  $query
     * @return CursorPaginator<int, Page>
     */
    protected function paginateTableQuery(Builder $query): CursorPaginator
    {
        $recordsPerPage = $this->getTableRecordsPerPage();
        $perPage = $recordsPerPage === 'all' ? $query->count() : (int) $recordsPerPage;

        return $query->cursorPaginate(
            perPage: $perPage,
            cursorName: (in_array($this->getTable()->getQueryStringIdentifier(), [null, '', '0'], true) ? 'list-pages' : $this->getTable()->getQueryStringIdentifier()) . '_cursor',
        );
    }
}

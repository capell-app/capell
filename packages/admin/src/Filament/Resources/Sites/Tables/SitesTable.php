<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Sites\Tables;

use Capell\Admin\Actions\Sites\BuildSiteDeletionImpactDescriptionAction;
use Capell\Admin\Contracts\Extenders\SiteRecordActionExtender;
use Capell\Admin\Enums\FilamentColorEnum;
use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Filament\Actions\Table\ReplicateSiteAction;
use Capell\Admin\Filament\Components\Tables\Actions\EditAction;
use Capell\Admin\Filament\Components\Tables\Columns\BlueprintColumn;
use Capell\Admin\Filament\Components\Tables\Columns\DateColumn;
use Capell\Admin\Filament\Components\Tables\Columns\IdentifierColumn;
use Capell\Admin\Filament\Components\Tables\Columns\LanguageColumn;
use Capell\Admin\Filament\Components\Tables\Columns\LanguagesColumn;
use Capell\Admin\Filament\Components\Tables\Columns\NameColumn;
use Capell\Admin\Filament\Components\Tables\Columns\StatusIconColumn;
use Capell\Admin\Filament\Components\Tables\Filters\StatusFilter;
use Capell\Admin\Filament\Contracts\TableConfigurator;
use Capell\Admin\Filament\Resources\Blueprints\BlueprintResource;
use Capell\Admin\Filament\Resources\Blueprints\Tables\BlueprintsTable;
use Capell\Admin\Filament\Resources\Pages\PageResource;
use Capell\Admin\Filament\Resources\Sites\Actions\ExportSitesBulkAction;
use Capell\Admin\Filament\Resources\Sites\Pages\ListSites;
use Capell\Admin\Filament\Resources\Themes\Tables\ThemesTable;
use Capell\Admin\Filament\Resources\Themes\ThemeResource;
use Capell\Core\Actions\DeleteSiteAction;
use Capell\Core\Actions\PreviewSiteDeletionImpactAction;
use Capell\Core\Actions\RestoreSiteAction;
use Capell\Core\Data\DeletionImpactData;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;
use Throwable;

class SitesTable implements TableConfigurator
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(
                fn (Builder $query): Builder => $query
                    ->with([
                        'creator',
                        'editor',
                        'language',
                        'translations.language',
                        'siteDomains.language',
                        'blueprint',
                        'theme.blueprint',
                    ])
                    ->withCount([
                        'pages',
                        'siteDomains',
                    ])
                    ->withoutGlobalScopes([
                        SoftDeletingScope::class,
                    ]),
            )
            ->defaultSort('name')
            ->columns(static::getTableColumns())
            ->filters(static::getTableFilters())
            ->recordClasses(fn (Site $record): ?string => match (true) {
                (bool) $record->deleted_at => 'table-row-warning',
                default => null,
            })
            ->recordActions([
                EditAction::make(),
                ActionGroup::make([
                    EditAction::make('edit-theme')
                        ->label(__('capell-admin::button.edit_theme'))
                        ->icon('heroicon-o-swatch')
                        ->record(fn (Site $record): Theme => $record->theme)
                        ->authorize(fn (): bool => auth()->user()?->can(ResourceEnum::Theme->permission('update')) === true)
                        ->schema(fn (Schema $schema): Schema => ThemeResource::form($schema))
                        ->slideOver()
                        ->modalWidth(Width::ScreenLarge)
                        ->mutateRecordDataUsing(ThemesTable::editorRecordData(...))
                        ->hidden(fn (Site $record): bool => ! $record->theme instanceof Theme || $record->theme->trashed()),
                    EditAction::make('edit-blueprint')
                        ->label(__('capell-admin::button.edit_blueprint'))
                        ->icon('heroicon-o-document-duplicate')
                        ->record(fn (Site $record): Blueprint => $record->blueprint)
                        ->authorize(fn (): bool => auth()->user()?->can(ResourceEnum::Blueprint->permission('update')) === true)
                        ->schema(fn (Schema $schema): Schema => BlueprintResource::form($schema))
                        ->modalWidth(Width::ScreenLarge)
                        ->slideOver()
                        ->mutateRecordDataUsing(function (array $data, Blueprint $record): array {
                            $data['type'] = BlueprintsTable::recordTypeName($record);

                            return $data;
                        })
                        ->using(BlueprintsTable::updateBlueprint(...))
                        ->hidden(fn (Site $record): bool => ! $record->blueprint instanceof Blueprint || $record->blueprint->trashed()),
                    ReplicateSiteAction::make(),
                    DeleteAction::make()
                        ->modalDescription(fn (Site $record): string => static::deletionImpactDescription($record))
                        ->using(fn (Site $record): bool => DeleteSiteAction::run($record)),
                    RestoreAction::make()
                        ->using(fn (Site $record): bool => RestoreSiteAction::run($record)),
                    ...collect(app()->tagged(SiteRecordActionExtender::TAG))
                        ->flatMap(fn (SiteRecordActionExtender $extender): array => $extender->actions())
                        ->all(),
                ])
                    ->color('gray'),
            ])
            ->toolbarActions([
                ExportSitesBulkAction::make(),
                DeleteBulkAction::make()
                    ->modalDescription(fn (EloquentCollection|Collection|LazyCollection $records): string => static::bulkDeletionImpactDescription($records))
                    ->using(static::deleteBulk(...)),
                RestoreBulkAction::make()
                    ->using(static::restoreBulk(...)),
                ForceDeleteBulkAction::make(),
            ])
            ->emptyStateHeading(__('capell-admin::generic.no_sites'))
            ->emptyStateDescription(__('capell-admin::generic.no_sites_description'))
            ->emptyStateIcon('heroicon-o-globe-alt');
    }

    protected static function deletionImpactDescription(Site $site): string
    {
        return BuildSiteDeletionImpactDescriptionAction::run(
            PreviewSiteDeletionImpactAction::run($site),
        );
    }

    /**
     * @param  EloquentCollection<int, Site>|Collection<int, Site>|LazyCollection<int, Site>  $records
     */
    protected static function bulkDeletionImpactDescription(EloquentCollection|Collection|LazyCollection $records): string
    {
        return BuildSiteDeletionImpactDescriptionAction::run(
            $records->reduce(
                fn (DeletionImpactData $impact, Site $site): DeletionImpactData => $impact->add(
                    PreviewSiteDeletionImpactAction::run($site),
                ),
                new DeletionImpactData,
            ),
        );
    }

    /**
     * @param  EloquentCollection<int, Site>|Collection<int, Site>|LazyCollection<int, Site>  $records
     */
    protected static function deleteBulk(DeleteBulkAction $action, EloquentCollection|Collection|LazyCollection $records): void
    {
        $isFirstException = true;

        $records->each(function (Site $site) use ($action, &$isFirstException): void {
            try {
                if (! DeleteSiteAction::run($site)) {
                    $action->reportBulkProcessingFailure();
                }
            } catch (Throwable $throwable) {
                $action->reportBulkProcessingFailure();

                if ($isFirstException) {
                    report($throwable);

                    $isFirstException = false;
                }
            }
        });
    }

    /**
     * @param  EloquentCollection<int, Site>|Collection<int, Site>|LazyCollection<int, Site>  $records
     */
    protected static function restoreBulk(RestoreBulkAction $action, EloquentCollection|Collection|LazyCollection $records): void
    {
        $isFirstException = true;

        $records->each(function (Site $site) use ($action, &$isFirstException): void {
            try {
                if (! RestoreSiteAction::run($site)) {
                    $action->reportBulkProcessingFailure();
                }
            } catch (Throwable $throwable) {
                $action->reportBulkProcessingFailure();

                if ($isFirstException) {
                    report($throwable);

                    $isFirstException = false;
                }
            }
        });
    }

    /** @return array<int, mixed> */
    protected static function getTableColumns(): array
    {
        return [
            IdentifierColumn::make('id'),
            NameColumn::make('name')
                ->defaultBadge(),
            TextColumn::make('siteDomains.full_url')
                ->label(__('capell-admin::table.domains'))
                ->getStateUsing(fn (Site $record): ?string => $record->siteDomains->sortByDesc('default')->first()?->full_url)
                ->url(fn (?string $state): ?string => $state, shouldOpenInNewTab: true)
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
            LanguageColumn::make('language')
                ->toggleable(),
            TextColumn::make('translation.contents')
                ->label(__('capell-admin::table.content'))
                ->sortable()
                ->searchable()
                ->limit(200)
                ->wrap()
                ->color(FilamentColorEnum::LightGray->value)
                ->html()
                ->listWithLineBreaks()
                ->formatStateUsing(
                    fn (ListSites $livewire, TextColumn $column, Site $record): string => e(Str::limit(
                        $record->translation->title ?? '',
                        $column->getCharacterLimit() ?? 200,
                        $column->getCharacterLimitEnd() ?? '...',
                    )),
                )
                ->description(function (ListSites $livewire, TextColumn $column, Site $record): ?HtmlString {
                    $content = $record->translation?->content;

                    if ($content === null || $content === '') {
                        return null;
                    }

                    return new HtmlString(
                        e(
                            Str::limit(
                                $content,
                                $column->getCharacterLimit() ?? 200,
                                $column->getCharacterLimitEnd() ?? '...',
                            ),
                        ),
                    );
                })
                ->toggleable(isToggledHiddenByDefault: true),
            LanguagesColumn::make('translations.language'),
            TextColumn::make('theme.name')
                ->label(__('capell-admin::table.theme'))
                ->icon(function (Site $record): ?string {
                    $theme = $record->getRelationValue('theme');
                    $type = $theme instanceof Theme ? $theme->blueprint : null;

                    return $type instanceof Blueprint ? ($type->admin['icon'] ?? null) : null;
                })
                ->sortable()
                ->limit(30)
                ->toggleable(isToggledHiddenByDefault: true),
            BlueprintColumn::make('blueprint.name')
                ->label(__('capell-admin::table.site_type')),
            TextColumn::make('pages_count')
                ->label(__('capell-admin::table.total_pages'))
                ->alignCenter()
                ->numeric()
                ->sortable()
                ->disabledClick()
                ->formatStateUsing(function (Site $record, int $state): ?HtmlString {
                    if ($state === 0) {
                        return null;
                    }

                    $url = PageResource::getUrl('index', ['activeTab' => $record->id]);

                    return new HtmlString(
                        Blade::render('capell-admin::components.tables.url', ['state' => $state, 'url' => $url]),
                    );
                })
                ->toggleable(),
            TextColumn::make('site_domains_count')
                ->label(__('capell-admin::table.total_domains'))
                ->alignCenter()
                ->numeric()
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
            StatusIconColumn::make('status'),
            DateColumn::make('created_at'),
            DateColumn::make('updated_at'),
            DateColumn::make('deleted_at'),
        ];
    }

    /** @return array<int, mixed> */
    protected static function getTableFilters(): array
    {
        return [
            SelectFilter::make('blueprint_id')
                ->label(__('capell-admin::form.site_type'))
                ->relationship(
                    name: 'blueprint',
                    titleAttribute: 'name',
                    modifyQueryUsing: fn (Builder $query): Builder => $query->enabled()->siteType(),
                ),
            Filter::make('filter')
                ->schema([
                    Select::make('language_id')
                        ->label(__('capell-admin::form.language'))
                        ->options(function (): array {
                            /** @var class-string<Language> $model */
                            $model = Language::class;

                            return $model::getOptions()->all();
                        }),
                ])
                ->indicateUsing(function (array $data): array {
                    $indicators = [];

                    if (isset($data['language_id']) && $data['language_id'] !== '') {
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
                })
                ->query(fn (Builder $query, array $data): Builder => $query->when(
                    $data['language_id'],
                    fn (Builder $query) => $query
                        ->where(
                            fn (Builder $query): Builder => $query->where('language_id', $data['language_id'])
                                ->orWhereHas(
                                    'translations',
                                    fn (BuilderContract $query): BuilderContract => $query->where('language_id', $data['language_id']),
                                )
                                ->orWhereHas(
                                    'siteDomains',
                                    fn (BuilderContract $query): BuilderContract => $query->where('language_id', $data['language_id']),
                                ),
                        ),
                )),

            SelectFilter::make('theme_id')
                ->label(__('capell-admin::form.theme'))
                ->relationship(name: 'theme', titleAttribute: 'name'),

            StatusFilter::make('status'),

            TrashedFilter::make(),
        ];
    }
}

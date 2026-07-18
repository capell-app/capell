<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\PageUrls\Tables;

use Capell\Admin\Enums\PageUrlTypeEnum;
use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Filament\Components\Tables\Actions\EditAction;
use Capell\Admin\Filament\Components\Tables\Columns\DateColumn;
use Capell\Admin\Filament\Components\Tables\Columns\IdentifierColumn;
use Capell\Admin\Filament\Components\Tables\Columns\LanguageColumn;
use Capell\Admin\Filament\Components\Tables\Columns\StatusIconColumn;
use Capell\Admin\Filament\Contracts\TableConfigurator;
use Capell\Admin\Filament\Resources\PageUrls\Schemas\PageUrlForm;
use Capell\Admin\Support\AdminSurfaceLookup;
use Capell\Admin\Support\PageUrlPresenter;
use Capell\Admin\Support\SiteScope;
use Capell\Core\Data\PageVariationData;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\PageUrl;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\ReplicateAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

class PageUrlsTable implements TableConfigurator
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(
                fn (Builder $query): Builder => $query->withWhereHas('language')
                    ->whereHasMorph(
                        'pageable',
                        self::pageableMorphTypes(),
                        fn (BuilderContract $query): BuilderContract => $query,
                    )
                    ->with([
                        'pageable' => function (Relation $relation): Relation {
                            if ($relation instanceof MorphTo) {
                                $relation->morphWith(self::pageableMorphRelations());
                            }

                            return $relation;
                        },
                    ])
                    ->withWhereHas(
                        'site',
                        fn (BuilderContract $query): BuilderContract => $query->withWhereHas('siteDomain'),
                    ),
            )
            ->defaultSort('created_at', 'desc')
            ->columns(static::getTableColumns())
            ->filters([
                SelectFilter::make('type')
                    ->label(__('capell-admin::table.type'))
                    ->options(PageUrlTypeEnum::options())
                    ->indicateUsing(
                        fn (array $data): ?string => match ($data['value']) {
                            'default' => self::translationString('capell-admin::generic.default'),
                            'alias' => self::translationString('capell-admin::generic.alias'),
                            'redirect' => self::translationString('capell-admin::generic.redirect'),
                            default => null,
                        },
                    )
                    ->query(function (Builder $query, array $data): Builder {
                        if ($data['value'] === null) {
                            return $query;
                        }

                        if ($data['value'] === '') {
                            return $query->whereNull('type');
                        }

                        return $query->where('type', $data['value']);
                    }),
                SelectFilter::make('site_id')
                    ->label(__('capell-admin::form.site'))
                    ->relationship(
                        name: 'site',
                        titleAttribute: 'name',
                        modifyQueryUsing: fn (Builder $query): Builder => SiteScope::applyForCurrentActor($query, 'id'),
                    ),
                SelectFilter::make('language_id')
                    ->label(__('capell-admin::form.language'))
                    ->relationship(name: 'language', titleAttribute: 'name'),
                TrashedFilter::make(),
            ])
            ->filtersFormColumns(4)
            ->filtersLayout(FiltersLayout::AboveContent)
            ->recordActions([
                EditAction::make()
                    ->modalHeading(__('capell-admin::generic.edit_page_url'))
                    ->modalDescription(fn (PageUrl $record): string => PageUrlPresenter::displayUrl($record)),
                ActionGroup::make([
                    Action::make('editPage')
                        ->label(__('capell-admin::button.edit_page'))
                        ->icon('heroicon-o-arrow-top-right-on-square')
                        ->url(function (PageUrl $record): string {
                            $resourceClass = AdminSurfaceLookup::resource(ResourceEnum::Page, $record->pageable->blueprint->admin['resource'] ?? null);

                            return $resourceClass::getUrl('edit', ['record' => $record->pageable]);
                        }),
                    ReplicateAction::make()
                        ->record(null)
                        ->schema(fn (Schema $schema): Schema => PageUrlForm::configure($schema))
                        ->fillForm(
                            fn (PageUrl $record): array => [
                                'site_id' => $record->site_id,
                                'language_id' => $record->language_id,
                                'pageable_id' => $record->pageable->getKey(),
                                'pageable_type' => $record->pageable->getMorphClass(),
                                'type' => $record->type,
                                'url' => $record->url,
                                'status' => $record->status,
                            ],
                        ),
                    DeleteAction::make(),
                ])
                    ->color('gray'),
            ])
            ->toolbarActions([
                DeleteBulkAction::make(),
                RestoreBulkAction::make(),
                ForceDeleteBulkAction::make(),
            ])
            ->emptyStateHeading(__('capell-admin::generic.no_page_urls'))
            ->emptyStateDescription(__('capell-admin::generic.no_page_urls_description'))
            ->emptyStateIcon('heroicon-o-link');
    }

    /** @return list<Column> */
    protected static function getTableColumns(): array
    {
        return [
            IdentifierColumn::make('id'),
            TextColumn::make('url')
                ->label(__('capell-admin::table.url'))
                ->sortable()
                ->searchable(query: self::applyUrlSearch(...))
                ->size('sm')
                ->wrap()
                ->description(fn (PageUrl $record): string => $record->pageable->name)
                ->url(
                    fn (PageUrl $record): ?string => PageUrlPresenter::fullUrl($record),
                    shouldOpenInNewTab: true,
                ),
            TextColumn::make('type')
                ->label(__('capell-admin::table.type'))
                ->sortable(),
            LanguageColumn::make('language')
                ->toggleable(),
            StatusIconColumn::make('status')
                ->toggleable(false),
            DateColumn::make('deleted_at'),
        ];
    }

    /**
     * @param  Builder<PageUrl>  $query
     * @return Builder<PageUrl>
     */
    protected static function applyUrlSearch(Builder $query, string $search): Builder
    {
        $query->where(
            fn (Builder $query): Builder => $query->where('url', 'like', sprintf('%%%s%%', $search))
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

        return $query;
    }

    protected static function applyFullUrlSearch(BuilderContract $query, string $search): BuilderContract
    {
        $bindings = [
            config('capell-frontend.default_scheme', request()->getScheme()),
            parse_url((string) config('app.url'), PHP_URL_HOST),
            sprintf('%s%%', $search),
        ];

        $query->whereColumn('site_domains.language_id', 'page_urls.language_id');

        if (in_array(DB::getDriverName(), ['sqlite', 'testing'], true)) {
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
     * @return list<class-string>
     */
    private static function pageableMorphTypes(): array
    {
        $types = collect(CapellCore::getPageVariations())
            ->map(fn (PageVariationData $pageVariation): string => $pageVariation->model)
            ->unique()
            ->values()
            ->all();

        return array_values($types);
    }

    private static function translationString(string $key): string
    {
        $value = __($key);

        return is_string($value) ? $value : $key;
    }

    /**
     * @return array<class-string, list<string>>
     */
    private static function pageableMorphRelations(): array
    {
        return collect(self::pageableMorphTypes())
            ->mapWithKeys(fn (string $model): array => [$model => ['blueprint']])
            ->all();
    }
}

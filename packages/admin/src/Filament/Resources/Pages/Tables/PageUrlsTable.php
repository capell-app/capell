<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Pages\Tables;

use Awcodes\BadgeableColumn\Components\BadgeableColumn;
use Capell\Admin\Filament\Components\Tables\Actions\CreateAction;
use Capell\Admin\Filament\Components\Tables\Actions\EditAction;
use Capell\Admin\Filament\Components\Tables\Columns\IdentifierColumn;
use Capell\Admin\Filament\Components\Tables\Columns\LanguageColumn;
use Capell\Admin\Filament\Contracts\TableConfigurator;
use Capell\Admin\Filament\Resources\Pages\RelationManagers\UrlsRelationManager;
use Capell\Admin\Support\PageUrlPresenter;
use Capell\Core\Enums\UrlTypeEnum;
use Capell\Core\Models\PageUrl;
use Filament\Actions\DeleteBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class PageUrlsTable implements TableConfigurator
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(
                fn (Builder $query): Builder => $query->select('page_urls.*')
                    ->join('languages', 'languages.id', '=', 'page_urls.language_id')
                    ->whereNull('languages.deleted_at')
                    ->with([
                        'language',
                        'site.siteDomains',
                        'siteDomain',
                    ])
                    ->withoutGlobalScopes([SoftDeletingScope::class]),
            )
            ->description(__('capell-admin::generic.page_urls_description'))
            ->emptyStateHeading(__('capell-admin::generic.no_page_urls'))
            ->emptyStateDescription(__('capell-admin::generic.no_page_urls_description'))
            ->emptyStateIcon('heroicon-o-link')
            ->columns([
                IdentifierColumn::make('id'),
                BadgeableColumn::make('url')
                    ->label(__('capell-admin::table.url'))
                    ->sortable()
                    ->size('sm')
                    ->color('primary')
                    ->url(fn (PageUrl $record): ?string => PageUrlPresenter::fullUrl($record), shouldOpenInNewTab: true)
                    ->getStateUsing(fn (PageUrl $record): string => PageUrlPresenter::displayUrl($record))
                    ->suffixBadges([]),
                LanguageColumn::make('language'),
                IconColumn::make('type')
                    ->label(__('capell-admin::table.type'))
                    ->tooltip(fn (PageUrl $url): ?string => $url->type?->getLabel())
                    ->sortable(),
            ])
            ->defaultSort(function (Builder $query): void {
                $query->orderBy('languages.default', 'desc')
                    ->orderBy('languages.name');
            })
            ->headerActions([
                CreateAction::make()
                    ->mutateDataUsing(function (array $data, UrlsRelationManager $livewire): array {
                        $data['pageable_id'] = $livewire->ownerRecord->getKey();
                        $data['pageable_type'] = $livewire->ownerRecord->getMorphClass();
                        $data['site_id'] = $livewire->ownerRecord->site_id;

                        return $data;
                    })
                    ->after(static::afterAction(...)),
            ])
            ->filters([
                SelectFilter::make('language_id')
                    ->label(__('capell-admin::form.language'))
                    ->relationship(name: 'language', titleAttribute: 'name'),
                SelectFilter::make('type')
                    ->label(__('capell-admin::table.type'))
                    ->options(UrlTypeEnum::class),
            ])
            ->toolbarActions([
                DeleteBulkAction::make()
                    ->after(static::afterAction(...)),
            ])
            ->recordActions([
                EditAction::make()
                    ->mutateDataUsing(function (array $data, UrlsRelationManager $livewire): array {
                        $data['site_id'] = $livewire->ownerRecord->site_id;

                        return $data;
                    })
                    ->after(static::afterAction(...)),
            ]);
    }

    protected static function afterAction(UrlsRelationManager $livewire, ?PageUrl $record): void
    {
        $livewire->dispatch('refresh-alerts');
    }
}

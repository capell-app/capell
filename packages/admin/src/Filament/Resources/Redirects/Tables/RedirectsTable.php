<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Redirects\Tables;

use Capell\Admin\Filament\Components\Tables\Actions\EditAction;
use Capell\Admin\Filament\Components\Tables\Columns\DateColumn;
use Capell\Admin\Filament\Components\Tables\Columns\IdentifierColumn;
use Capell\Admin\Filament\Components\Tables\Columns\LanguageColumn;
use Capell\Admin\Filament\Components\Tables\Columns\StatusIconColumn;
use Capell\Admin\Filament\Contracts\TableConfigurator;
use Capell\Admin\Filament\Resources\Languages\LanguageResource;
use Capell\Admin\Filament\Resources\Sites\SiteResource;
use Capell\Admin\Support\SiteScope;
use Capell\Core\Enums\RedirectStatusCodeEnum;
use Capell\Core\Models\Language;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\RedirectHealthSnapshot;
use Capell\Core\Models\Site;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class RedirectsTable implements TableConfigurator
{
    /** @var array<int, object|null> */
    private static array $redirectHealthCache = [];

    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(
                fn (Builder $query): Builder => $query->with(['language', 'site', 'redirectHealthSnapshot']),
            )
            ->defaultSort('created_at', 'desc')
            ->columns(static::getTableColumns())
            ->filters(static::getTableFilters())
            ->filtersFormColumns(4)
            ->filtersLayout(FiltersLayout::AboveContent)
            ->recordActions([
                EditAction::make()
                    ->modalHeading(__('capell-admin::generic.edit_redirect'))
                    ->visible(fn (PageUrl $record): bool => $record->is_manual),
                ActionGroup::make([
                    Action::make('edit-site')
                        ->label(__('capell-admin::button.edit_site'))
                        ->icon('heroicon-o-building-storefront')
                        ->url(fn (PageUrl $record): ?string => self::siteEditUrl($record))
                        ->hidden(fn (PageUrl $record): bool => self::siteEditUrl($record) === null),
                    EditAction::make('edit-language')
                        ->label(__('capell-admin::button.edit'))
                        ->icon('heroicon-o-language')
                        ->record(fn (PageUrl $record): ?Language => self::language($record))
                        ->authorize(fn (PageUrl $record): bool => self::canEditLanguage($record))
                        ->schema(fn (Schema $schema): Schema => LanguageResource::form($schema))
                        ->slideOver()
                        ->hidden(fn (PageUrl $record): bool => ! self::canEditLanguage($record)),
                    DeleteAction::make(),
                ])
                    ->color('gray'),
            ])
            ->toolbarActions([
                DeleteBulkAction::make(),
                RestoreBulkAction::make(),
                ForceDeleteBulkAction::make(),
            ])
            ->emptyStateHeading(__('capell-admin::generic.no_redirects'))
            ->emptyStateDescription(__('capell-admin::generic.no_redirects_description'))
            ->emptyStateIcon('heroicon-o-arrow-path');
    }

    /** @return array<int, mixed> */
    protected static function getTableFilters(): array
    {
        return [
            SelectFilter::make('status_code')
                ->label(__('capell-admin::table.status_code'))
                ->options(RedirectStatusCodeEnum::class),
            TernaryFilter::make('is_manual')
                ->label(__('capell-admin::table.is_manual'))
                ->trueLabel(__('capell-admin::generic.manual'))
                ->falseLabel(__('capell-admin::generic.auto'))
                ->queries(
                    true: fn (Builder $query): Builder => $query->where('is_manual', true),
                    false: fn (Builder $query): Builder => $query->where('is_manual', false),
                    blank: fn (Builder $query): Builder => $query,
                ),
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
            TernaryFilter::make('status')
                ->label(__('capell-admin::table.status'))
                ->trueLabel(__('capell-admin::generic.active'))
                ->falseLabel(__('capell-admin::generic.disabled'))
                ->queries(
                    true: fn (Builder $query): Builder => $query->where('status', true),
                    false: fn (Builder $query): Builder => $query->where('status', false),
                    blank: fn (Builder $query): Builder => $query,
                ),
            TrashedFilter::make(),
            SelectFilter::make('hit_count_bucket')
                ->label(__('capell-admin::table.hit_count_bucket'))
                ->options([
                    'none' => __('capell-admin::table.hit_count_bucket_none'),
                    'any' => __('capell-admin::table.hit_count_bucket_any'),
                    'ten_plus' => __('capell-admin::table.hit_count_bucket_ten_plus'),
                ])
                ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                    'none' => $query->where('hit_count', 0),
                    'any' => $query->where('hit_count', '>', 0),
                    'ten_plus' => $query->where('hit_count', '>=', 10),
                    default => $query,
                }),
        ];
    }

    /** @return array<int, mixed> */
    protected static function getTableColumns(): array
    {
        return [
            IdentifierColumn::make('id'),
            TextColumn::make('url')
                ->label(__('capell-admin::table.source_url'))
                ->sortable()
                ->searchable()
                ->size('sm')
                ->wrap(),
            TextColumn::make('target_url')
                ->label(__('capell-admin::table.target_url'))
                ->searchable()
                ->size('sm')
                ->wrap()
                ->description(fn (PageUrl $record): ?string => self::targetDescription($record))
                ->placeholder(__('capell-admin::generic.auto_resolved')),
            TextColumn::make('status_code')
                ->label(__('capell-admin::table.status_code'))
                ->badge()
                ->sortable(),
            TextColumn::make('is_manual')
                ->label(__('capell-admin::table.is_manual'))
                ->formatStateUsing(fn (bool $state): string => $state
                    ? __('capell-admin::generic.manual')
                    : __('capell-admin::generic.auto'))
                ->badge()
                ->color(fn (bool $state): string => $state ? 'primary' : 'gray')
                ->toggleable(),
            LanguageColumn::make('language')
                ->toggleable(),
            StatusIconColumn::make('status')
                ->toggleable(false),
            TextColumn::make('hit_count')
                ->label(__('capell-admin::table.hit_count'))
                ->sortable()
                ->numeric()
                ->toggleable(),
            DateColumn::make('last_hit_at')
                ->label(__('capell-admin::table.last_hit_at'))
                ->toggleable(),
            TextColumn::make('chain_warning')
                ->label(__('capell-admin::table.chain_warning'))
                ->state(fn (PageUrl $record): string => self::redirectHealthState($record))
                ->badge()
                ->color(fn (string $state): string => $state === __('capell-admin::table.chain_warning_detected') ? 'warning' : 'gray')
                ->toggleable(),
            TextColumn::make('creator.name')
                ->label(__('capell-admin::table.created_by'))
                ->toggleable(isToggledHiddenByDefault: true),
            DateColumn::make('created_at')
                ->toggleable(isToggledHiddenByDefault: true),
        ];
    }

    private static function redirectHealthState(PageUrl $record): string
    {
        $redirectHealth = self::redirectHealthFor($record);

        if (! is_object($redirectHealth) || ! property_exists($redirectHealth, 'has_chain')) {
            return __('capell-admin::table.chain_warning_unknown');
        }

        return $redirectHealth->has_chain === true
            ? __('capell-admin::table.chain_warning_detected')
            : __('capell-admin::table.chain_warning_none');
    }

    private static function siteEditUrl(PageUrl $record): ?string
    {
        $site = $record->getRelationValue('site');

        return $site instanceof Site && SiteResource::canEdit($site)
            ? SiteResource::getUrl('edit', ['record' => $site])
            : null;
    }

    private static function language(PageUrl $record): ?Language
    {
        $language = $record->getRelationValue('language');

        return $language instanceof Language && ! $language->trashed() ? $language : null;
    }

    private static function canEditLanguage(PageUrl $record): bool
    {
        $language = self::language($record);

        return $language instanceof Language && LanguageResource::canEdit($language);
    }

    private static function targetDescription(PageUrl $record): ?string
    {
        $pageable = $record->getRelationValue('pageable');

        if (! is_object($pageable) || ! property_exists($pageable, 'name')) {
            return null;
        }

        return is_string($pageable->name) ? $pageable->name : null;
    }

    private static function redirectHealthFor(PageUrl $record): ?object
    {
        $pageUrlId = $record->id;

        if (! array_key_exists($pageUrlId, self::$redirectHealthCache)) {
            if ($record->relationLoaded('redirectHealthSnapshot')) {
                self::$redirectHealthCache[$pageUrlId] = $record->redirectHealthSnapshot;

                return self::$redirectHealthCache[$pageUrlId];
            }

            /** @var class-string<Model> $redirectHealthSnapshotClass */
            $redirectHealthSnapshotClass = RedirectHealthSnapshot::class;

            self::$redirectHealthCache[$pageUrlId] = $redirectHealthSnapshotClass::query()
                ->where('page_url_id', $pageUrlId)
                ->first();
        }

        return self::$redirectHealthCache[$pageUrlId];
    }
}

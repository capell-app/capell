<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Languages\Tables;

use Capell\Admin\Actions\SetupSiteLanguageAction;
use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Filament\Components\Tables\Actions\EditAction;
use Capell\Admin\Filament\Components\Tables\Actions\ReplicateAction;
use Capell\Admin\Filament\Components\Tables\Columns\DateColumn;
use Capell\Admin\Filament\Components\Tables\Columns\IdentifierColumn;
use Capell\Admin\Filament\Components\Tables\Columns\LanguageColumn;
use Capell\Admin\Filament\Components\Tables\Columns\NameColumn;
use Capell\Admin\Filament\Components\Tables\Columns\StatusIconColumn;
use Capell\Admin\Filament\Components\Tables\Filters\StatusFilter;
use Capell\Admin\Filament\Contracts\TableConfigurator;
use Capell\Admin\Filament\Resources\Languages\Pages\ManageLanguages;
use Capell\Admin\Filament\Resources\Languages\Schemas\LanguageForm;
use Capell\Admin\Support\AdminSurfaceLookup;
use Capell\Admin\Support\SiteScope;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Illuminate\Support\LazyCollection;

class LanguagesTable implements TableConfigurator
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns(static::getTableColumns())
            ->recordActions([
                EditAction::make(),
                ActionGroup::make([
                    ReplicateAction::make()
                        ->schema(fn (Schema $schema): Schema => LanguageForm::configure($schema))
                        ->modalWidth(Width::ScreenLarge)
                        ->modalDescription(__('capell-admin::generic.create_language_info'))
                        ->excludeAttributes(['setup', 'setup_sites', 'sites_count'])
                        ->after(function (Language $replica, ReplicateAction $action): void {
                            $actionData = $action->getRawData();

                            if (
                                isset($actionData['setup']) && $actionData['setup'] === true
                                && isset($actionData['setup_sites']) && is_array($actionData['setup_sites']) && $actionData['setup_sites'] !== []
                            ) {
                                /** @var Builder<Site> $siteQuery */
                                $siteQuery = SiteScope::applyForCurrentActor(Site::query(), 'id')
                                    ->whereIn('id', $actionData['setup_sites']);

                                $siteQuery->each(function (Site $site) use ($replica): void {
                                    SetupSiteLanguageAction::run($site, $replica);
                                });
                            }
                        }),
                    DeleteAction::make()
                        ->before(function (ManageLanguages $livewire, Language $record, DeleteAction $action): void {
                            if (! $livewire->validateDelete($record)) {
                                $action->cancel();
                            }
                        }),
                ])
                    ->color('gray'),
            ])
            ->filters([
                StatusFilter::make('status'),
                TrashedFilter::make(),
            ])
            ->toolbarActions([
                DeleteBulkAction::make()
                    ->before(function (ManageLanguages $livewire, DeleteBulkAction $action, EloquentCollection|Collection|LazyCollection $records): void {
                        $records->each(function (Language $record) use ($livewire, $action): void {
                            if (! $livewire->validateDelete($record)) {
                                $action->cancel();
                            }
                        });
                    }),
                RestoreBulkAction::make(),
                ForceDeleteBulkAction::make(),
            ])
            ->reorderable('order')
            ->emptyStateHeading(__('capell-admin::generic.no_languages'))
            ->emptyStateDescription(__('capell-admin::generic.no_languages_description'))
            ->emptyStateIcon('heroicon-o-language');
    }

    /** @return array<int, mixed> */
    protected static function getTableColumns(): array
    {
        return [
            IdentifierColumn::make('id'),
            NameColumn::make('name')
                ->defaultBadge(),
            TextColumn::make('code')
                ->label(__('capell-admin::table.code'))
                ->alignCenter()
                ->sortable()
                ->searchable(),
            TextColumn::make('locale')
                ->label(__('capell-admin::table.locale'))
                ->alignCenter()
                ->sortable()
                ->searchable()
                ->toggleable(),
            LanguageColumn::make('language')
                ->getStateUsing(fn (Language $record): Language => $record)
                ->toggleable(),
            TextColumn::make('sites_count')
                ->label(__('capell-admin::table.total_sites'))
                ->alignCenter()
                ->sortable()
                ->numeric()
                ->toggleable()
                ->disabledClick()
                ->formatStateUsing(function (Language $record, int $state): ?HtmlString {
                    if ($state === 0) {
                        return null;
                    }

                    $url = AdminSurfaceLookup::resource(ResourceEnum::Site)::getUrl('index', ['filters[filter][language_id]' => $record->id]);

                    return new HtmlString(Blade::render('capell-admin::components.tables.url', ['state' => $state, 'url' => $url]));
                }),
            TextColumn::make('order')
                ->label(__('capell-admin::table.ordering'))
                ->sortable()
                ->alignCenter()
                ->toggleable(isToggledHiddenByDefault: true),
            StatusIconColumn::make('status'),
            DateColumn::make('created_at'),
            DateColumn::make('updated_at'),
            DateColumn::make('deleted_at'),
        ];
    }
}

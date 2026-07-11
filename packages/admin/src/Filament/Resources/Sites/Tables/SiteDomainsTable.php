<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Sites\Tables;

use Capell\Admin\Filament\Components\Tables\Actions\CreateAction;
use Capell\Admin\Filament\Components\Tables\Actions\EditAction;
use Capell\Admin\Filament\Components\Tables\Columns\BadgeableColumn;
use Capell\Admin\Filament\Components\Tables\Columns\DateColumn;
use Capell\Admin\Filament\Components\Tables\Columns\IdentifierColumn;
use Capell\Admin\Filament\Components\Tables\Columns\LanguageColumn;
use Capell\Admin\Filament\Components\Tables\Columns\StatusIconColumn;
use Capell\Admin\Filament\Contracts\TableConfigurator;
use Capell\Admin\Filament\Resources\Sites\RelationManagers\SiteDomainsRelationManager;
use Capell\Admin\Filament\Resources\Sites\Widgets\SiteAlertsWidget;
use Capell\Core\Models\SiteDomain;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Number;

class SiteDomainsTable implements TableConfigurator
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(
                fn (Builder $query) => $query->with([
                    'creator',
                    'editor',
                    'language',
                ])
                    ->withCount('pageUrls')
                    ->withoutGlobalScopes([
                        SoftDeletingScope::class,
                    ]),
            )
            ->defaultSort('default', 'desc')
            ->description(__('capell-admin::generic.site_domains_description'))
            ->columns(self::getTableColumns())
            ->headerActions([
                CreateAction::make()
                    ->before(function (CreateAction $action, array $data, SiteDomainsRelationManager $livewire): void {
                        if (! $livewire::validateExists([
                            'scheme' => $data['scheme'],
                            'host' => $data['domain'],
                            'path' => $data['path'],
                        ])) {
                            $action->halt();
                        }
                    })
                    ->after(static::afterAction(...)),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->toolbarActions([
                DeleteBulkAction::make(),
                RestoreBulkAction::make(),
                ForceDeleteBulkAction::make(),
            ])
            ->emptyStateHeading(__('capell-admin::generic.no_site_domains'))
            ->emptyStateDescription(__('capell-admin::generic.no_site_domains_description'))
            ->emptyStateIcon('heroicon-o-globe-alt')
            ->recordActions([
                EditAction::make()
                    ->modalHeading(__('capell-admin::generic.edit_site_domain'))
                    ->modalDescription(fn (SiteDomain $record): string => $record->full_url)
                    ->afterFormValidated(
                        function (EditAction $action, SiteDomain $record, SiteDomainsRelationManager $livewire): void {
                            $data = $action->getRawData();

                            $urlParts = [
                                'scheme' => $data['scheme'],
                                'host' => $data['domain'],
                                'path' => $data['path'],
                            ];

                            if (! $livewire::validateExists($urlParts, $record)) {
                                $action->halt();
                            }
                        },
                    )
                    ->after(static::afterAction(...)),
                ActionGroup::make([
                    DeleteAction::make()
                        ->after(static::afterAction(...)),
                ])
                    ->color('gray'),
            ]);
    }

    /** @return array<int, mixed> */
    protected static function getTableColumns(): array
    {
        return [
            IdentifierColumn::make('id'),
            BadgeableColumn::make('full_url')
                ->label(__('capell-admin::table.url'))
                ->weight(FontWeight::Medium)
                ->searchable()
                ->sortable(['scheme', 'domain', 'path'])
                ->url(fn (string|array $state): string => is_array($state) ? $state[0] : $state, shouldOpenInNewTab: true)
                ->defaultBadge(),
            LanguageColumn::make('language')
                ->toggleable(),
            TextColumn::make('urls_count')
                ->label(__('capell-admin::generic.pages'))
                ->sortable()
                ->alignCenter()
                ->numeric()
                ->formatStateUsing(fn (int $state): bool|string => Number::forHumans($state)),
            StatusIconColumn::make('status'),
            DateColumn::make('created_at'),
            DateColumn::make('updated_at'),
            DateColumn::make('deleted_at'),
        ];
    }

    protected static function afterAction(SiteDomainsRelationManager $livewire): void
    {
        $livewire->dispatch('refresh-alerts')->to(SiteAlertsWidget::class);
    }
}

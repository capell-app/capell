<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\RelationManagers;

use BackedEnum;
use Capell\Admin\Filament\Components\Tables\Columns\DateColumn;
use Capell\Admin\Filament\Components\Tables\Columns\IdentifierColumn;
use Capell\Admin\Filament\Components\Tables\Columns\MediaLibraryImageColumn;
use Capell\Admin\Filament\Components\Tables\Columns\Page\AncestorsColumn;
use Capell\Admin\Filament\Components\Tables\Columns\Page\PageNameColumn;
use Capell\Admin\Filament\Components\Tables\Columns\SiteColumn;
use Capell\Admin\Filament\Concerns\HasRelationManagerBadge;
use Capell\Admin\Filament\Resources\Pages\PageResource;
use Capell\Admin\Support\SiteScope;
use Capell\Core\Actions\GetEditPageResourceUrlAction;
use Capell\Core\Contracts\Pageable;
use Capell\Core\Enums\AssetEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property Model $ownerRecord
 */
abstract class AbstractPagesRelationManager extends RelationManager
{
    use HasRelationManagerBadge;

    protected static string $relationship = 'pages';

    #[Override]
    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('capell-admin::tab.pages');
    }

    #[Override]
    public static function getIcon(Model $ownerRecord, string $pageClass): null|string|BackedEnum
    {
        if (static::$icon !== null) {
            return static::$icon;
        }

        if (is_a($pageClass, PageResource::class, true)) {
            $icon = $pageClass::getNavigationIcon();

            return $icon instanceof Htmlable ? $icon->toHtml() : $icon;
        }

        $icon = CapellCore::getAsset(AssetEnum::Page)->getIcon();

        return $icon instanceof Htmlable ? $icon->toHtml() : $icon;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(
                fn (Builder $query): Builder => $this->modifyQuery(
                    $query->with([
                        'ancestors.type',
                        'editor',
                        'image',
                        'type',
                        'pageUrl.siteDomain',
                    ]),
                ),
            )
            ->headerActions([
                Action::make('view_pages')
                    ->label(__('capell-admin::button.view_pages'))
                    ->size('sm')
                    ->color('primary')
                    ->outlined()
                    ->url(function (AbstractPagesRelationManager $livewire): string {
                        $parameters = [];

                        if ($livewire->ownerRecord instanceof Layout) {
                            $parameters['activeTab'] = 0;
                            $parameters['filters[layout_id][value]'] = $livewire->ownerRecord->id;
                        } elseif ($livewire->ownerRecord instanceof Site) {
                            $parameters['activeTab'] = $livewire->ownerRecord->id;
                        }

                        return PageResource::getUrl('index', $parameters);
                    }),
            ])
            ->description(fn (self $livewire, Table $table): ?string => $livewire->getDescription($table))
            ->emptyStateHeading(fn (self $livewire): string => $livewire->getEmptyStateHeading())
            ->emptyStateDescription(fn (self $livewire): string => $livewire->getEmptyStateDescription())
            ->emptyStateIcon('heroicon-o-document-text')
            ->columns([
                IdentifierColumn::make('id'),
                PageNameColumn::make('name')
                    ->wrap()
                    ->withTypeIcon()
                    ->urlDescription(),
                AncestorsColumn::make('ancestors'),
                SiteColumn::make('site.name')
                    ->hidden(fn (self $livewire): bool => $livewire->isSiteColumnHidden()),
                MediaLibraryImageColumn::make('image')
                    ->collection('image'),
                DateColumn::make('updated_at')
                    ->sortable(false),
            ])
            ->filters([
                SelectFilter::make('site_id')
                    ->label(__('capell-admin::form.site'))
                    ->relationship(
                        name: 'site',
                        titleAttribute: 'name',
                        modifyQueryUsing: fn (Builder $query): Builder => SiteScope::applyForCurrentActor($query, 'id')->ordered(),
                    )
                    ->hidden(fn (self $livewire): bool => $livewire->isSiteColumnHidden()),
                SelectFilter::make('blueprint_id')
                    ->label(__('capell-admin::form.type'))
                    ->relationship(
                        name: 'type',
                        titleAttribute: 'name',
                        modifyQueryUsing: fn (Builder $query) => $query->pageType()->ordered(),
                    ),
            ])
            ->recordUrl(fn (Pageable $record): ?string => GetEditPageResourceUrlAction::run($record))
            ->recordActions([
                EditAction::make()
                    ->iconButton()
                    ->url(fn (Pageable $record): ?string => GetEditPageResourceUrlAction::run($record))
                    ->tooltip(function (EditAction $action): string {
                        $label = $action->getLabel();

                        return $label instanceof Htmlable ? $label->toHtml() : (string) $label;
                    }),
            ]);
    }

    /**
     * @param  Builder<Page>  $query
     * @return Builder<Page>
     */
    protected function modifyQuery(Builder $query): Builder
    {
        return SiteScope::applyForCurrentActor($query);
    }

    protected function getDescription(Table $table): ?string
    {
        return null;
    }

    protected function getEmptyStateHeading(): string
    {
        return __('capell-admin::generic.no_pages_found');
    }

    protected function getEmptyStateDescription(): string
    {
        return __('capell-admin::generic.no_pages_description');
    }

    private function isSiteColumnHidden(): bool
    {
        return $this->ownerRecord instanceof Site;
    }
}

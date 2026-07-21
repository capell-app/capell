<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Sites;

use BackedEnum;
use Capell\Admin\Enums\ConfiguratorTypeEnum;
use Capell\Admin\Filament\Concerns\HasConfiguredForm;
use Capell\Admin\Filament\Concerns\HasConfiguredTable;
use Capell\Admin\Filament\Concerns\HasNavigationBadge;
use Capell\Admin\Filament\RelationManagers\ActivityHistoryRelationManager;
use Capell\Admin\Filament\Resources\Sites\Pages\CreateSite;
use Capell\Admin\Filament\Resources\Sites\Pages\EditSite;
use Capell\Admin\Filament\Resources\Sites\Pages\ListSites;
use Capell\Admin\Filament\Resources\Sites\RelationManagers\SiteDomainsRelationManager;
use Capell\Admin\Filament\Resources\Sites\Schemas\SiteForm;
use Capell\Admin\Filament\Resources\Sites\Tables\SitesTable;
use Capell\Admin\Filament\Resources\Sites\Widgets\SiteAlertsWidget;
use Capell\Admin\Policies\SitePolicy;
use Capell\Admin\Support\Search\AppliesNameSearchRelevance;
use Capell\Admin\Support\SiteScope;
use Capell\Core\Models\Site;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Override;

class SiteResource extends Resource
{
    use AppliesNameSearchRelevance;
    use HasConfiguredForm;
    use HasConfiguredTable;
    use HasNavigationBadge;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::BuildingStorefront;

    protected static ?string $recordTitleAttribute = 'name';

    protected static bool $isGloballySearchable = true;

    protected static string $formConfigurator = SiteForm::class;

    protected static string $tableConfigurator = SitesTable::class;

    protected static ?int $navigationSort = 6;

    #[Override]
    public static function table(Table $table): Table
    {
        return static::configuredTable($table, ConfiguratorTypeEnum::Site);
    }

    #[Override]
    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'translations.title'];
    }

    #[Override]
    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return SiteScope::applyForCurrentActor(parent::getGlobalSearchEloquentQuery(), 'id')
            ->with('translation');
    }

    /** @param Builder<Site> $query */
    #[Override]
    public static function modifyGlobalSearchQuery(Builder $query, string $search): void
    {
        parent::modifyGlobalSearchQuery($query, $search);

        static::applyNameSearchRelevance($query, $search);
    }

    /**
     * @param  Model&Site  $record
     * @return array|string[]
     */
    #[Override]
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        $title = $record->title;

        if (! is_string($title) || $title === '' || $title === $record->name) {
            return [];
        }

        return [
            __('capell-admin::generic.title') => $title,
        ];
    }

    /**
     * @return class-string<Site>
     */
    #[Override]
    public static function getModel(): string
    {
        return Site::class;
    }

    #[Override]
    public static function getNavigationLabel(): string
    {
        return (string) (__('capell-admin::navigation.sites'));
    }

    #[Override]
    public static function getNavigationGroup(): ?string
    {
        return (string) __('capell-admin::navigation.group_websites');
    }

    #[Override]
    public static function getPages(): array
    {
        return [
            'index' => ListSites::route('/'),
            'create' => CreateSite::route('/create'),
            'edit' => EditSite::route('/{record}/edit'),
        ];
    }

    #[Override]
    public static function getPluralModelLabel(): string
    {
        return __('capell-admin::generic.sites');
    }

    /** @return class-string<SitePolicy> */
    public static function getPolicy(): ?string
    {
        return SitePolicy::class;
    }

    #[Override]
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);

        SiteScope::applyForCurrentActor($query, 'id');

        return $query;
    }

    #[Override]
    public static function getRelations(): array
    {
        return [
            ActivityHistoryRelationManager::class,
            SiteDomainsRelationManager::class,
        ];
    }

    #[Override]
    public static function getWidgets(): array
    {
        return [
            SiteAlertsWidget::class,
        ];
    }
}

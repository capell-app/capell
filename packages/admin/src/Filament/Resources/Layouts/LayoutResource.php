<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Layouts;

use BackedEnum;
use Capell\Admin\Filament\Concerns\HasConfiguredForm;
use Capell\Admin\Filament\Concerns\HasConfiguredTable;
use Capell\Admin\Filament\Concerns\HasNavigationBadge;
use Capell\Admin\Filament\Concerns\Validate\LayoutValidation;
use Capell\Admin\Filament\Contracts\ValidatesDelete;
use Capell\Admin\Filament\RelationManagers\ActivityHistoryRelationManager;
use Capell\Admin\Filament\Resources\Layouts\Pages\CreateLayout;
use Capell\Admin\Filament\Resources\Layouts\Pages\EditLayout;
use Capell\Admin\Filament\Resources\Layouts\Pages\ListLayouts;
use Capell\Admin\Filament\Resources\Layouts\RelationManagers\PagesRelationManager;
use Capell\Admin\Filament\Resources\Layouts\Schemas\LayoutForm;
use Capell\Admin\Filament\Resources\Layouts\Tables\LayoutsTable;
use Capell\Admin\Support\Search\AppliesNameSearchRelevance;
use Capell\Admin\Support\SiteScope;
use Capell\Core\Models\Layout;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Override;

class LayoutResource extends Resource implements ValidatesDelete
{
    use AppliesNameSearchRelevance;
    use HasConfiguredForm;
    use HasConfiguredTable;
    use HasNavigationBadge;
    use LayoutValidation;

    protected static ?string $recordTitleAttribute = 'name';

    protected static bool $isGloballySearchable = true;

    protected static string $formConfigurator = LayoutForm::class;

    protected static string $tableConfigurator = LayoutsTable::class;

    protected static ?int $navigationSort = 3;

    #[Override]
    public static function getEloquentQuery(): Builder
    {
        return self::applySiteScope(parent::getEloquentQuery())
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }

    #[Override]
    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'key'];
    }

    #[Override]
    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return self::applySiteScope(parent::getGlobalSearchEloquentQuery())
            ->with([
                'site:id,name,default',
            ]);
    }

    /** @param Builder<Layout> $query */
    #[Override]
    public static function modifyGlobalSearchQuery(Builder $query, string $search): void
    {
        parent::modifyGlobalSearchQuery($query, $search);

        static::applyNameSearchRelevance($query, $search);
    }

    /**
     * @param  Layout  $record
     */
    #[Override]
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        $details = [];

        if ($record->site !== null) {
            $details[__('capell-admin::generic.site')] = $record->site->name;
        }

        return $details;
    }

    #[Override]
    public static function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return config('capell-admin.resources.layout.icon', Heroicon::OutlinedSquares2x2);
    }

    #[Override]
    public static function getActiveNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return config('capell-admin.resources.layout.active_icon', Heroicon::Squares2x2);
    }

    /**
     * @return class-string<Layout>
     */
    #[Override]
    public static function getModel(): string
    {
        return Layout::class;
    }

    #[Override]
    public static function getNavigationGroup(): ?string
    {
        return (string) __('capell-admin::navigation.group_websites');
    }

    #[Override]
    public static function getNavigationParentItem(): ?string
    {
        return null;
    }

    #[Override]
    public static function getNavigationLabel(): string
    {
        return (string) (__('capell-admin::navigation.layouts'));
    }

    #[Override]
    public static function getPages(): array
    {
        return [
            'index' => ListLayouts::route('/'),
            'edit' => EditLayout::route('/{record}/edit'),
            'create' => CreateLayout::route('/create'),
        ];
    }

    #[Override]
    public static function getPluralModelLabel(): string
    {
        return __('capell-admin::generic.layouts');
    }

    #[Override]
    public static function getRelations(): array
    {
        return [
            ActivityHistoryRelationManager::class,
            PagesRelationManager::class,
        ];
    }

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    private static function applySiteScope(Builder $query): Builder
    {
        $actor = auth()->user();

        if (! $actor instanceof Authenticatable || SiteScope::isGlobalActor($actor)) {
            return $query;
        }

        $assignedSiteIds = $actor->getAssignedSiteIds();

        return $query->where(function (Builder $query) use ($assignedSiteIds): void {
            $query->whereNull('site_id');

            if ($assignedSiteIds->isNotEmpty()) {
                $query->orWhereIn('site_id', $assignedSiteIds);
            }
        });
    }
}

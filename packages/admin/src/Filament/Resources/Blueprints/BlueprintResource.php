<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Blueprints;

use BackedEnum;
use Capell\Admin\Filament\Concerns\HasConfiguredForm;
use Capell\Admin\Filament\Concerns\HasConfiguredTable;
use Capell\Admin\Filament\Concerns\HasNavigationBadge;
use Capell\Admin\Filament\Resources\Blueprints\Pages\ManageBlueprints;
use Capell\Admin\Filament\Resources\Blueprints\Schemas\BlueprintForm;
use Capell\Admin\Filament\Resources\Blueprints\Tables\BlueprintsTable;
use Capell\Admin\Support\Search\AppliesNameSearchRelevance;
use Capell\Core\Models\Blueprint;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Override;

class BlueprintResource extends Resource
{
    use AppliesNameSearchRelevance;
    use HasConfiguredForm;
    use HasConfiguredTable;
    use HasNavigationBadge;

    protected static ?string $recordTitleAttribute = 'name';

    protected static string $formConfigurator = BlueprintForm::class;

    protected static string $tableConfigurator = BlueprintsTable::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentDuplicate;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::DocumentDuplicate;

    protected static ?int $navigationSort = 10;

    #[Override]
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with([
                'creator',
                'editor',
            ])
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }

    #[Override]
    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'key', 'admin->notes', 'component'];
    }

    /** @param Builder<Blueprint> $query */
    #[Override]
    public static function modifyGlobalSearchQuery(Builder $query, string $search): void
    {
        parent::modifyGlobalSearchQuery($query, $search);

        static::applyNameSearchRelevance($query, $search);
    }

    /**
     * @return class-string<Blueprint>
     */
    #[Override]
    public static function getModel(): string
    {
        return Blueprint::class;
    }

    #[Override]
    public static function getNavigationGroup(): ?string
    {
        return (string) (__('capell-admin::navigation.group_system'));
    }

    #[Override]
    public static function getNavigationLabel(): string
    {
        return (string) __('capell-admin::navigation.blueprints');
    }

    #[Override]
    public static function getModelLabel(): string
    {
        return (string) __('capell-admin::generic.blueprint');
    }

    #[Override]
    public static function getPluralModelLabel(): string
    {
        return (string) __('capell-admin::generic.blueprints');
    }

    #[Override]
    public static function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return config('capell-admin.resources.blueprint.icon', config('capell-admin.resources.type.icon', static::$navigationIcon));
    }

    #[Override]
    public static function getActiveNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return config('capell-admin.resources.blueprint.active_icon', config('capell-admin.resources.type.active_icon', static::$activeNavigationIcon));
    }

    #[Override]
    public static function getPages(): array
    {
        return [
            'index' => ManageBlueprints::route('/'),
        ];
    }
}

<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Blueprints\Pages;

use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Filament\Actions\Blueprint\CreateBlueprintAction;
use Capell\Admin\Filament\Concerns\AppliesNameSearchRelevanceToTable;
use Capell\Admin\Filament\Concerns\HasImportExportHeaderActions;
use Capell\Admin\Filament\Concerns\Validate\BlueprintValidation;
use Capell\Admin\Filament\Contracts\ValidatesDelete;
use Capell\Admin\Filament\Resources\Blueprints\BlueprintResource;
use Capell\Admin\Filament\Resources\Blueprints\Widgets\BlueprintsAlertsWidget;
use Capell\Admin\Support\AdminSurfaceLookup;
use Capell\Core\Enums\BlueprintSubjectEnum;
use Capell\Core\Models\Blueprint;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Override;

class ManageBlueprints extends ManageRecords implements ValidatesDelete
{
    use AppliesNameSearchRelevanceToTable;
    use BlueprintValidation;
    use HasImportExportHeaderActions;

    /** @return class-string<BlueprintResource> */
    #[Override]
    public static function getResource(): string
    {
        /** @var class-string<BlueprintResource> $resource */
        $resource = AdminSurfaceLookup::resource(ResourceEnum::Blueprint);

        return $resource;
    }

    #[Override]
    public function getTabs(): array
    {
        $tabs = [];

        $tabs['all'] = Tab::make(__('capell-admin::generic.all'));

        /** @var class-string<Blueprint> $model */
        $model = Blueprint::class;

        $blueprints = $model::getTypes();
        foreach ($blueprints as $type => $count) {
            $label = BlueprintSubjectEnum::tryFrom($type)?->getLabel() ?? str($type)->headline()->toString();
            $tabs[$type] = Tab::make($label)
                ->badge($count)
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('type', $type));
        }

        return $tabs;
    }

    #[Override]
    public function getSubheading(): string|Htmlable|null
    {
        return __('capell-admin::generic.types_subheading');
    }

    /**
     * @param  Builder<Blueprint>  $query
     * @return Builder<Blueprint>
     */
    protected function applyGlobalSearchToTableQuery(Builder $query): Builder
    {
        parent::applyGlobalSearchToTableQuery($query);

        return $this->applyNameSearchRelevanceToTableQuery($query);
    }

    #[Override]
    protected function getHeaderWidgets(): array
    {
        return [
            BlueprintsAlertsWidget::class,
        ];
    }

    #[Override]
    protected function getActions(): array
    {
        return $this->prependImportHeaderAction([
            CreateBlueprintAction::make('create')
                ->model(Blueprint::class),
        ]);
    }
}

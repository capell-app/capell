<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Layouts\Pages;

use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Filament\Actions\CreateAction;
use Capell\Admin\Filament\Concerns\AppliesNameSearchRelevanceToTable;
use Capell\Admin\Filament\Concerns\HasImportExportHeaderActions;
use Capell\Admin\Filament\Concerns\HasSiteTableFilterTabs;
use Capell\Admin\Filament\Concerns\Validate\LayoutValidation;
use Capell\Admin\Filament\Contracts\ValidatesDelete;
use Capell\Admin\Filament\Resources\Layouts\LayoutResource;
use Capell\Admin\Support\AdminSurfaceLookup;
use Capell\Core\Models\Layout;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Override;

class ListLayouts extends ListRecords implements ValidatesDelete
{
    use AppliesNameSearchRelevanceToTable;
    use HasImportExportHeaderActions;
    use HasSiteTableFilterTabs;
    use LayoutValidation;

    protected string $siteRelation = 'layouts';

    /** @return class-string<LayoutResource> */
    #[Override]
    public static function getResource(): string
    {
        /** @var class-string<LayoutResource> $resource */
        $resource = AdminSurfaceLookup::resource(ResourceEnum::Layout);

        return $resource;
    }

    #[Override]
    public function getSubheading(): string|Htmlable|null
    {
        return __('capell-admin::generic.layout_info');
    }

    /**
     * @param  Builder<Layout>  $query
     * @return Builder<Layout>
     */
    protected function applyGlobalSearchToTableQuery(Builder $query): Builder
    {
        parent::applyGlobalSearchToTableQuery($query);

        return $this->applyNameSearchRelevanceToTableQuery($query);
    }

    #[Override]
    protected function getActions(): array
    {
        return $this->prependImportHeaderAction([
            CreateAction::make()
                ->slideOver()
                ->redirectAfterCreate(),
        ]);
    }
}

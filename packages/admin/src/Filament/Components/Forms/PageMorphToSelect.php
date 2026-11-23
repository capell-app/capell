<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms;

use Capell\Core\Data\PageVariationData;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Permissions\SiteAccess;
use Closure;
use Filament\Forms\Components\MorphToSelect;
use Filament\Forms\Components\MorphToSelect\Type;
use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PageMorphToSelect extends MorphToSelect
{
    protected ?Closure $modifyKeySelectOptionsQueryUsing = null;

    protected ?Closure $siteIdUsing = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->typeSelectToggleButtons()
            ->label(__('capell-admin::form.page'))
            ->types(fn (): array => $this->getPageTypes());
    }

    public static function getDefaultName(): ?string
    {
        return 'pageable';
    }

    /**
     * Limits the options to pages of one site, resolved when the options are read.
     */
    public function restrictToSite(Closure $siteId): self
    {
        $this->siteIdUsing = $siteId;

        return $this;
    }

    public function modifyKeySelectOptionsQueryUsing(Closure $closure): self
    {
        $this->modifyKeySelectOptionsQueryUsing = $closure;

        return $this;
    }

    /**
     * @return array<int, Type>
     */
    private function getPageTypes(): array
    {
        return collect(CapellCore::getPageVariations())
            ->map(fn (PageVariationData $pageData): Type => $this->makePageType($pageData))
            ->values()
            ->all();
    }

    private function makePageType(PageVariationData $pageData): Type
    {
        return Type::make($pageData->model)
            ->titleAttribute($pageData->titleAttribute ?? 'name')
            ->modifyKeySelectUsing(
                fn (Select $select): Select => $select->placeholder(
                    __('capell-admin::form.select_placeholder', ['name' => $pageData->name]),
                ),
            )
            ->modifyOptionsQueryUsing(fn (Builder $query, Select $component): Builder => $this->scopedOptionsQuery($query, $component));
    }

    /**
     * Options only ever come from sites the actor may use, whatever the form adds on top.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    private function scopedOptionsQuery(Builder $query, Select $component): Builder
    {
        $query = SiteAccess::current()->scope($query);

        if ($this->siteIdUsing instanceof Closure) {
            $query->where($query->qualifyColumn('site_id'), (int) $component->evaluate($this->siteIdUsing));
        }

        if (! $this->modifyKeySelectOptionsQueryUsing instanceof Closure) {
            return $query;
        }

        return $component->evaluate($this->modifyKeySelectOptionsQueryUsing, [
            'query' => $query,
            'builder' => $query,
            'select' => $component,
        ]) ?? $query;
    }
}

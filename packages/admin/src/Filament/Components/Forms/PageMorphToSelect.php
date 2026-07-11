<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms;

use Capell\Core\Data\PageVariationData;
use Capell\Core\Facades\CapellCore;
use Closure;
use Filament\Forms\Components\MorphToSelect;
use Filament\Forms\Components\MorphToSelect\Type;
use Filament\Forms\Components\Select;

class PageMorphToSelect extends MorphToSelect
{
    protected ?Closure $modifyKeySelectOptionsQueryUsing = null;

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
            ->modifyOptionsQueryUsing($this->modifyKeySelectOptionsQueryUsing);
    }
}

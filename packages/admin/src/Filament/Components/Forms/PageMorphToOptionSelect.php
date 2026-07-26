<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms;

use Capell\Core\Data\Database\SqlFragment;
use Capell\Core\Data\PageVariationData;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Facades\CapellDatabase;
use Closure;
use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PageMorphToOptionSelect extends OptionMorphToSelect
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
     * @return array<int, OptionMorphToSelectType>
     */
    private function getPageTypes(): array
    {
        return collect(CapellCore::getPageVariations())
            ->map(fn (PageVariationData $pageData): OptionMorphToSelectType => $this->makePageType($pageData))
            ->values()
            ->all();
    }

    private function makePageType(PageVariationData $pageData): OptionMorphToSelectType
    {
        $titleAttribute = $pageData->titleAttribute ?? 'name';

        return OptionMorphToSelectType::make($pageData->name)
            ->label((string) str($pageData->name)->replace(['_', '-'], ' ')->ucfirst())
            ->getOptionsUsing(function (Select $component) use ($pageData, $titleAttribute): array {
                $query = $this->getOptionsQuery($component, $pageData);

                $query->orderBy($titleAttribute)
                    ->limit($component->getOptionsLimit());

                return $query->pluck($titleAttribute, $query->getModel()->getKeyName())->all();
            })
            ->getSearchResultsUsing(function (Select $component, string $search) use ($pageData, $titleAttribute): array {
                $query = $this->getOptionsQuery($component, $pageData);

                $searchColumn = $this->wrappedSearchColumn($query, $titleAttribute);
                $relevance = CapellDatabase::for($query->getModel())
                    ->queryDialect()
                    ->textRelevance(SqlFragment::raw($searchColumn), $search);

                $query->whereLike($titleAttribute, sprintf('%%%s%%', $search));
                $relevance->applyOrder($query->getQuery());
                $query->orderBy($titleAttribute)->limit($component->getOptionsLimit());

                return $query->pluck($titleAttribute, $query->getModel()->getKeyName())->all();
            })
            ->getOptionLabelUsing(function (Select $component, int|string|null $value) use ($pageData, $titleAttribute): ?string {
                if (in_array($value, [null, '', '0'], true)) {
                    return null;
                }

                $query = $this->getOptionsQuery($component, $pageData);
                $keyName = $query->getModel()->getKeyName();

                return $query->where($keyName, $value)->value($titleAttribute);
            });
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function wrappedSearchColumn(Builder $query, string $titleAttribute): string
    {
        if (! preg_match('/^[A-Za-z_]\w*(?:\.[A-Za-z_]\w*)?$/', $titleAttribute)) {
            return $query->getQuery()->getGrammar()->wrap($query->qualifyColumn('name'));
        }

        return $query->getQuery()->getGrammar()->wrap($query->qualifyColumn($titleAttribute));
    }

    /**
     * @return Builder<Model>
     */
    private function getOptionsQuery(Select $component, PageVariationData $pageData): Builder
    {
        $model = $pageData->model;
        $query = $model::query();

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

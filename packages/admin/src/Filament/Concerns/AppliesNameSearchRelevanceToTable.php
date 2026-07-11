<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Concerns;

use Capell\Admin\Support\Search\AppliesNameSearchRelevance;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

trait AppliesNameSearchRelevanceToTable
{
    use AppliesNameSearchRelevance;

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    protected function applyNameSearchRelevanceToTableQuery(Builder $query): Builder
    {
        $search = $this->getTableSearch();

        if (blank($search) || $this->getTableSortColumn() !== null) {
            return $query;
        }

        return self::applyNameSearchRelevance($query, $search);
    }
}

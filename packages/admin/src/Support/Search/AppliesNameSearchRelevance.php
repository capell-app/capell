<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Search;

use Capell\Core\Data\Database\SqlFragment;
use Capell\Core\Facades\CapellDatabase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

trait AppliesNameSearchRelevance
{
    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    protected static function applyNameSearchRelevance(Builder $query, string $search): Builder
    {
        $grammar = $query->getQuery()->getGrammar();
        $nameColumn = $grammar->wrap($query->qualifyColumn('name'));
        // Laravel's grammar quotes this fixed model column as a trusted SQL identifier.
        /** @var literal-string $nameColumn */
        $keyColumn = $query->qualifyColumn($query->getModel()->getKeyName());
        $relevance = CapellDatabase::for($query->getModel())
            ->queryDialect()
            ->textRelevance(SqlFragment::raw($nameColumn), $search);

        $query->reorder();
        $relevance->applyOrder($query->getQuery());

        return $query
            ->orderBy($query->qualifyColumn('name'))
            ->orderBy($keyColumn);
    }
}

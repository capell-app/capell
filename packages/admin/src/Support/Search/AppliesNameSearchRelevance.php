<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Search;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

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
        $driver = $query->getModel()->getConnection()->getDriverName();
        $shouldNormalizeCase = in_array($driver, ['pgsql', 'sqlite'], true);
        $searchExpression = $shouldNormalizeCase ? Str::lower($search) : $search;
        $nameExpression = $shouldNormalizeCase ? 'LOWER(' . $nameColumn . ')' : $nameColumn;
        $positionFunction = $driver === 'pgsql' ? 'STRPOS' : 'INSTR';

        return $query
            ->reorder()
            ->orderByRaw(
                'CASE WHEN ' . $nameExpression . ' = ? THEN 0 WHEN ' . $nameExpression . ' LIKE ? THEN 1 ELSE 2 END',
                [$searchExpression, $searchExpression . '%'],
            )
            ->orderByRaw('CASE WHEN ' . $nameExpression . ' LIKE ? THEN 0 ELSE 1 END', ['%' . $searchExpression . '%'])
            ->orderByRaw($positionFunction . '(' . $nameExpression . ', ?) ASC', [$searchExpression])
            ->orderBy($query->qualifyColumn('name'))
            ->orderBy($keyColumn);
    }
}

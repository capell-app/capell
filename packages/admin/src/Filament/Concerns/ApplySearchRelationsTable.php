<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Concerns;

use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait ApplySearchRelationsTable
{
    use AppliesNameSearchRelevanceToTable;

    /**
     * @return array<string, array<int|string, string>>
     */
    abstract public function getSearchRelationColumns(): array;

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    protected function applyGlobalSearchToTableQuery(Builder $query): Builder
    {
        $search = $this->getTableSearch();

        if (blank($search)) {
            return $query;
        }

        $search = mb_trim($search);

        $query->where(function (Builder $query) use ($search): void {
            foreach (explode(' ', $search) as $searchWord) {
                $query->where(function (Builder $query) use ($searchWord): void {
                    $isFirst = true;

                    foreach ($this->getTable()->getColumns() as $column) {
                        /*if ($column->isHidden()) {
                            continue;
                        }*/

                        if (! $column->isGloballySearchable()) {
                            continue;
                        }

                        $column->applySearchConstraint(
                            $query,
                            $searchWord,
                            $isFirst,
                        );
                    }
                });
            }

            $this->applySearchRelationsToTableQuery($search, $query);
        });

        $this->applyNameSearchRelevanceToTableQuery($query);

        return $query;
    }

    /**
     * @param  string|Expression<literal-string|int|float>  $searchColumn
     */
    protected function applyRelationColumnSearch(
        BuilderContract $query,
        string $searchTerm,
        string|Expression $searchColumn,
        string $searchColumnType,
        bool $isColumnFirst,
    ): void {
        if ($searchColumn instanceof Expression) {
            $searchColumnSql = (string) $searchColumn->getValue(DB::connection()->getQueryGrammar());
            $searchColumnExpression = $searchColumn;
        } else {
            $searchParent = null;

            if (Str::contains($searchColumn, '->')) {
                [$searchParent, $searchColumn] = explode('->', $searchColumn, 2);
            }

            $searchColumn = preg_replace('/[^a-zA-Z0-9]+/', '', $searchColumn) ?? '';

            $searchColumnSql = in_array($searchParent, [null, '', '0'], true)
                ? sprintf('`%s`', $searchColumn)
                : sprintf('JSON_EXTRACT(`%s`, "$.`%s`")', $searchParent, $searchColumn);
            $searchColumnExpression = new Expression($this->literalSql($searchColumnSql));
        }

        if ($searchColumnType === 'json' || $searchColumnType === 'json_data') {
            $searchString = DB::getPdo()->quote(sprintf('%%%s%%', $searchTerm));

            $jsonSearchParams = $searchColumnType === 'json_data'
                ? sprintf("%s, 'one', %s, NULL, '\$[*].data'", $searchColumnSql, $searchString)
                : sprintf("%s, 'one', %s", $searchColumnSql, $searchString);

            if ($isColumnFirst) {
                $query->whereRaw($this->literalSql(sprintf('json_extract(%s) IS NOT NULL', $jsonSearchParams)));
            } else {
                $query->orWhereRaw($this->literalSql(sprintf('json_extract(%s) IS NOT NULL', $jsonSearchParams)));
            }

            return;
        }

        if ($isColumnFirst) {
            $query->where(
                $searchColumnExpression,
                'like',
                sprintf('%%%s%%', $searchTerm),
            );
        } else {
            $query->orWhere(
                $searchColumnExpression,
                'like',
                sprintf('%%%s%%', $searchTerm),
            );
        }
    }

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    protected function applySearchRelationsToTableQuery(string $search, Builder $query): Builder
    {
        $searchTerm = Str::lower($search);

        foreach ($this->getSearchRelationColumns() as $searchRelation => $searchColumns) {
            $query->orWhereHas($searchRelation, function (BuilderContract $query) use ($searchTerm, $searchColumns): void {
                $isColumnFirst = true;

                foreach ($searchColumns as $searchColumn => $searchColumnType) {
                    if (is_numeric($searchColumn)) {
                        $searchColumn = $searchColumnType;
                        $searchColumnType = 'string';
                    }

                    $this->applyRelationColumnSearch(
                        $query,
                        $searchTerm,
                        $searchColumn,
                        $searchColumnType,
                        $isColumnFirst,
                    );

                    $isColumnFirst = false;
                }
            });
        }

        return $query;
    }

    /**
     * @return literal-string
     */
    private function literalSql(string $sql): string
    {
        /** @var literal-string $sql */
        return $sql;
    }
}

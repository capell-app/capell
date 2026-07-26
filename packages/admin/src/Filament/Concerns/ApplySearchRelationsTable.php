<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Concerns;

use Capell\Core\Data\Database\SqlFragment;
use Capell\Core\Facades\CapellDatabase;
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

            $grammar = $query->getQuery()->getGrammar();
            $searchColumnFragment = in_array($searchParent, [null, '', '0'], true)
                ? SqlFragment::raw($grammar->wrap($searchColumn))
                : CapellDatabase::for($query->getModel())->queryDialect()->jsonExtract(
                    SqlFragment::raw($grammar->wrap($searchParent)),
                    '$.' . $searchColumn,
                );
            $searchColumnSql = $searchColumnFragment->sql;
            $searchColumnExpression = in_array($searchParent, [null, '', '0'], true)
                ? $searchColumn
                : $searchParent . '->' . $searchColumn;
        }

        if ($searchColumnType === 'json' || $searchColumnType === 'json_data') {
            $search = CapellDatabase::for($query->getModel())->queryDialect()->jsonSearch(
                $searchColumnFragment ?? SqlFragment::raw($searchColumnSql),
                $searchTerm,
                $searchColumnType === 'json_data' ? '$[*].data' : '$',
            );

            if ($isColumnFirst) {
                $query->whereRaw($search->sql, $search->bindings);
            } else {
                $query->orWhereRaw($search->sql, $search->bindings);
            }

            return;
        }

        if ($isColumnFirst) {
            $query->whereLike(
                $searchColumnExpression,
                sprintf('%%%s%%', $searchTerm),
            );
        } else {
            $query->orWhereLike(
                $searchColumnExpression,
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

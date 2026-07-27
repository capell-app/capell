<?php

declare(strict_types=1);

namespace Capell\Core\Support\Database\QueryDialects;

use Capell\Core\Contracts\Database\DatabaseQueryDialect;
use Capell\Core\Data\Database\DatabaseFullTextSearch;
use Capell\Core\Data\Database\DatabaseSearchExpression;
use Capell\Core\Data\Database\SqlFragment;
use InvalidArgumentException;
use JsonException;

abstract class AbstractQueryDialect implements DatabaseQueryDialect
{
    /**
     * @param  non-empty-list<DatabaseSearchExpression>  $expressions
     */
    public function fullTextSearch(array $expressions, string $query, bool $native = false): DatabaseFullTextSearch
    {
        throw_if($expressions === [], InvalidArgumentException::class, 'Full-text search requires at least one expression.');

        $terms = $this->fullTextTerms($query);

        if ($terms === []) {
            return new DatabaseFullTextSearch(
                predicate: new SqlFragment('0 = 1'),
                relevance: new SqlFragment('0'),
                native: false,
            );
        }

        $predicateSql = [];
        $predicateBindings = [];
        $relevanceSql = [];
        $relevanceBindings = [];

        foreach ($terms as $term) {
            $termPredicateSql = [];
            $pattern = '%' . $this->escapeLike($term) . '%';

            foreach ($expressions as $searchExpression) {
                $expression = $searchExpression->expression;
                $match = sprintf("LOWER(COALESCE(%s, '')) LIKE ? ESCAPE '!'", $expression->sql);
                $termPredicateSql[] = $match;
                $predicateBindings = [...$predicateBindings, ...$expression->bindings, $pattern];
                $relevanceSql[] = sprintf('CASE WHEN %s THEN ? ELSE 0 END', $match);
                $relevanceBindings = [
                    ...$relevanceBindings,
                    ...$expression->bindings,
                    $pattern,
                    $searchExpression->weight,
                ];
            }

            $predicateSql[] = '(' . implode(' OR ', $termPredicateSql) . ')';
        }

        return new DatabaseFullTextSearch(
            predicate: new SqlFragment(implode(' AND ', $predicateSql), $predicateBindings),
            relevance: new SqlFragment(implode(' + ', $relevanceSql), $relevanceBindings),
            native: false,
        );
    }

    /**
     * @param  list<SqlFragment>  $fragments
     * @return list<mixed>
     */
    protected function bindings(array $fragments): array
    {
        return array_values(array_merge(...array_map(
            static fn (SqlFragment $fragment): array => $fragment->bindings,
            $fragments,
        )));
    }

    protected function relevance(SqlFragment $expression, string $needle, string $position, string $divisor): SqlFragment
    {
        $normalized = 'LOWER(' . $expression->sql . ')';
        $needle = mb_strtolower($needle);
        $expressionBindings = $expression->bindings;

        return new SqlFragment(
            sprintf(
                'CASE WHEN %1$s = ? THEN 0 WHEN %1$s LIKE ? THEN 1 WHEN %1$s LIKE ? THEN 2 ELSE 3 END + %2$s / %3$s',
                $normalized,
                $position,
                $divisor,
            ),
            [
                ...$expressionBindings,
                $needle,
                ...$expressionBindings,
                $needle . '%',
                ...$expressionBindings,
                '%' . $needle . '%',
                ...$expressionBindings,
                $needle,
            ],
        );
    }

    /**
     * @return list<non-empty-string>
     */
    protected function fullTextTerms(string $query): array
    {
        $terms = preg_split('/\s+/u', mb_strtolower(trim($query)));

        if (! is_array($terms)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            $terms,
            static fn (string $term): bool => $term !== '',
        )));
    }

    protected function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    /**
     * @throws JsonException
     */
    protected function jsonValue(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }
}

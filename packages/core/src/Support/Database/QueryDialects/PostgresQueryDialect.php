<?php

declare(strict_types=1);

namespace Capell\Core\Support\Database\QueryDialects;

use Capell\Core\Data\Database\DatabaseFullTextSearch;
use Capell\Core\Data\Database\DatabaseSearchExpression;
use Capell\Core\Data\Database\SqlFragment;
use Capell\Core\Enums\Database\DatabaseDateOperation;
use Override;

final class PostgresQueryDialect extends AbstractQueryDialect
{
    /**
     * @param  non-empty-list<DatabaseSearchExpression>  $expressions
     */
    #[Override]
    public function fullTextSearch(array $expressions, string $query, bool $native = false): DatabaseFullTextSearch
    {
        $fallback = parent::fullTextSearch($expressions, $query);
        $terms = $this->fullTextTerms($query);

        if (! $native || $terms === []) {
            return $fallback;
        }

        $document = implode(" || ' ' || ", array_map(
            static fn (DatabaseSearchExpression $expression): string => sprintf("COALESCE(%s, '')", $expression->expression->sql),
            $expressions,
        ));
        $expressionBindings = $this->bindings(array_map(
            static fn (DatabaseSearchExpression $expression): SqlFragment => $expression->expression,
            array_values($expressions),
        ));
        $prefixQuery = implode(' & ', array_map(
            static fn (string $term): string => "'" . str_replace(
                ['\\', "'"],
                ['\\\\', "''"],
                $term,
            ) . "':*",
            $terms,
        ));
        $vector = sprintf("to_tsvector('simple', %s)", $document);
        $queryExpression = "to_tsquery('simple', ?)";

        return new DatabaseFullTextSearch(
            predicate: new SqlFragment(
                sprintf('%s @@ %s', $vector, $queryExpression),
                [...$expressionBindings, $prefixQuery],
            ),
            relevance: $fallback->relevance,
            native: true,
        );
    }

    public function concatenate(SqlFragment ...$expressions): SqlFragment
    {
        return new SqlFragment(
            implode(' || ', array_map(static fn (SqlFragment $expression): string => $expression->sql, $expressions)),
            $this->bindings(array_values($expressions)),
        );
    }

    public function trimTrailingSlash(SqlFragment $expression): SqlFragment
    {
        return new SqlFragment(
            sprintf("RTRIM(%s, '/')", $expression->sql),
            $expression->bindings,
        );
    }

    public function textPosition(SqlFragment $expression, string $needle, bool $caseInsensitive = false): SqlFragment
    {
        $sql = $caseInsensitive ? 'LOWER(' . $expression->sql . ')' : $expression->sql;

        return new SqlFragment('STRPOS(' . $sql . ', ?)', [...$expression->bindings, $caseInsensitive ? mb_strtolower($needle) : $needle]);
    }

    public function textRelevance(SqlFragment $expression, string $needle): SqlFragment
    {
        return $this->relevance($expression, $needle, 'STRPOS(LOWER(' . $expression->sql . '), ?)', '1000.0');
    }

    public function date(DatabaseDateOperation $operation, SqlFragment $expression): SqlFragment
    {
        $sql = match ($operation) {
            DatabaseDateOperation::Year => 'EXTRACT(YEAR FROM %s)::INTEGER',
            DatabaseDateOperation::Month => 'EXTRACT(MONTH FROM %s)::INTEGER',
            DatabaseDateOperation::Day => 'EXTRACT(DAY FROM %s)::INTEGER',
            DatabaseDateOperation::Hour => 'EXTRACT(HOUR FROM %s)::INTEGER',
            DatabaseDateOperation::HourLabel => "TO_CHAR(%s, 'HH24:00')",
            DatabaseDateOperation::DayAbbreviation => "TO_CHAR(%s, 'Dy')",
            DatabaseDateOperation::DayMonthLabel => "TO_CHAR(%s, 'DD Mon')",
            DatabaseDateOperation::MonthYearLabel => "TO_CHAR(%s, 'Mon YY')",
        };

        return new SqlFragment(sprintf($sql, $expression->sql), $expression->bindings);
    }

    public function elapsedSeconds(SqlFragment $start, SqlFragment $end): SqlFragment
    {
        return new SqlFragment(
            sprintf('EXTRACT(EPOCH FROM (%s - %s))::INTEGER', $end->sql, $start->sql),
            $this->bindings([$end, $start]),
        );
    }

    public function jsonExtract(SqlFragment $expression, string $path): SqlFragment
    {
        return new SqlFragment('jsonb_path_query_first(' . $expression->sql . '::jsonb, ?::jsonpath)', [...$expression->bindings, $path]);
    }

    public function jsonContains(SqlFragment $expression, mixed $value, string $path = '$'): SqlFragment
    {
        return new SqlFragment(
            sprintf("EXISTS (SELECT 1 FROM jsonb_path_query(%s::jsonb, ?::jsonpath) AS capell_json_contains(value) CROSS JOIN (SELECT ?::jsonb AS candidate) AS capell_json_target WHERE capell_json_contains.value = capell_json_target.candidate OR capell_json_contains.value @> capell_json_target.candidate OR EXISTS (SELECT 1 FROM jsonb_array_elements(CASE WHEN jsonb_typeof(capell_json_contains.value) = 'array' THEN capell_json_contains.value ELSE jsonb_build_array(capell_json_contains.value) END) AS capell_json_element(value) WHERE capell_json_element.value = capell_json_target.candidate))", $expression->sql),
            [...$expression->bindings, $path, $this->jsonValue($value)],
        );
    }

    public function jsonSearch(SqlFragment $expression, SqlFragment $needle, string $path = '$'): SqlFragment
    {
        $searchPath = $path === '$' ? '$.**' : $path;

        return new SqlFragment(
            sprintf("EXISTS (SELECT 1 FROM jsonb_path_query(%s::jsonb, ?::jsonpath) AS capell_json_search(value) WHERE capell_json_search.value #>> '{}' ILIKE ('%%' || CAST(%s AS TEXT) || '%%'))", $expression->sql, $needle->sql),
            [...$expression->bindings, $searchPath, ...$needle->bindings],
        );
    }

    public function jsonExactSearch(SqlFragment $expression, SqlFragment $needle, string $path = '$'): SqlFragment
    {
        $searchPath = $path === '$' ? '$.**' : $path;

        return new SqlFragment(
            sprintf("EXISTS (SELECT 1 FROM jsonb_path_query(%s::jsonb, ?::jsonpath) AS capell_json_exact(value) WHERE jsonb_typeof(capell_json_exact.value) = 'string' AND capell_json_exact.value #>> '{}' = CAST(%s AS TEXT))", $expression->sql, $needle->sql),
            [...$expression->bindings, $searchPath, ...$needle->bindings],
        );
    }
}

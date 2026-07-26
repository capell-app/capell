<?php

declare(strict_types=1);

namespace Capell\Core\Support\Database\QueryDialects;

use Capell\Core\Data\Database\SqlFragment;
use Capell\Core\Enums\Database\DatabaseDateOperation;

final class PostgresQueryDialect extends AbstractQueryDialect
{
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
            "RTRIM({$expression->sql}, '/')",
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
            "EXISTS (SELECT 1 FROM jsonb_path_query({$expression->sql}::jsonb, ?::jsonpath) AS capell_json_contains(value) CROSS JOIN (SELECT ?::jsonb AS candidate) AS capell_json_target WHERE capell_json_contains.value = capell_json_target.candidate OR capell_json_contains.value @> capell_json_target.candidate OR EXISTS (SELECT 1 FROM jsonb_array_elements(CASE WHEN jsonb_typeof(capell_json_contains.value) = 'array' THEN capell_json_contains.value ELSE jsonb_build_array(capell_json_contains.value) END) AS capell_json_element(value) WHERE capell_json_element.value = capell_json_target.candidate))",
            [...$expression->bindings, $path, $this->jsonValue($value)],
        );
    }

    public function jsonSearch(SqlFragment $expression, string $needle, string $path = '$'): SqlFragment
    {
        $searchPath = $path === '$' ? '$.**' : $path;

        return new SqlFragment(
            "EXISTS (SELECT 1 FROM jsonb_path_query({$expression->sql}::jsonb, ?::jsonpath) AS capell_json_search(value) WHERE capell_json_search.value #>> '{}' ILIKE ?)",
            [...$expression->bindings, $searchPath, '%' . $needle . '%'],
        );
    }
}

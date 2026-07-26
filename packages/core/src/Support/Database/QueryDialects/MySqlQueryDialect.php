<?php

declare(strict_types=1);

namespace Capell\Core\Support\Database\QueryDialects;

use Capell\Core\Data\Database\SqlFragment;
use Capell\Core\Enums\Database\DatabaseDateOperation;

final class MySqlQueryDialect extends AbstractQueryDialect
{
    public function concatenate(SqlFragment ...$expressions): SqlFragment
    {
        return new SqlFragment(
            'CONCAT(' . implode(', ', array_map(static fn (SqlFragment $expression): string => $expression->sql, $expressions)) . ')',
            $this->bindings(array_values($expressions)),
        );
    }

    public function trimTrailingSlash(SqlFragment $expression): SqlFragment
    {
        return new SqlFragment(
            "TRIM(TRAILING '/' FROM {$expression->sql})",
            $expression->bindings,
        );
    }

    public function textPosition(SqlFragment $expression, string $needle, bool $caseInsensitive = false): SqlFragment
    {
        $sql = $caseInsensitive ? 'LOWER(' . $expression->sql . ')' : $expression->sql;

        return new SqlFragment('INSTR(' . $sql . ', ?)', [...$expression->bindings, $caseInsensitive ? mb_strtolower($needle) : $needle]);
    }

    public function textRelevance(SqlFragment $expression, string $needle): SqlFragment
    {
        return $this->relevance($expression, $needle, 'INSTR(LOWER(' . $expression->sql . '), ?)', '1000');
    }

    public function date(DatabaseDateOperation $operation, SqlFragment $expression): SqlFragment
    {
        $sql = match ($operation) {
            DatabaseDateOperation::Year => 'YEAR(%s)',
            DatabaseDateOperation::Month => 'MONTH(%s)',
            DatabaseDateOperation::Day => 'DAY(%s)',
            DatabaseDateOperation::Hour => 'HOUR(%s)',
            DatabaseDateOperation::HourLabel => "DATE_FORMAT(%s, '%%H:00')",
            DatabaseDateOperation::DayAbbreviation => "DATE_FORMAT(%s, '%%a')",
            DatabaseDateOperation::DayMonthLabel => "DATE_FORMAT(%s, '%%d %%b')",
            DatabaseDateOperation::MonthYearLabel => "DATE_FORMAT(%s, '%%b %%y')",
        };

        return new SqlFragment(sprintf($sql, $expression->sql), $expression->bindings);
    }

    public function elapsedSeconds(SqlFragment $start, SqlFragment $end): SqlFragment
    {
        return new SqlFragment(
            sprintf('TIMESTAMPDIFF(SECOND, %s, %s)', $start->sql, $end->sql),
            $this->bindings([$start, $end]),
        );
    }

    public function jsonExtract(SqlFragment $expression, string $path): SqlFragment
    {
        return new SqlFragment('JSON_EXTRACT(' . $expression->sql . ', ?)', [...$expression->bindings, $path]);
    }

    public function jsonContains(SqlFragment $expression, mixed $value, string $path = '$'): SqlFragment
    {
        return new SqlFragment('JSON_CONTAINS(' . $expression->sql . ', JSON_QUOTE(?), ?)', [...$expression->bindings, $value, $path]);
    }

    public function jsonSearch(SqlFragment $expression, string $needle, string $path = '$'): SqlFragment
    {
        return new SqlFragment('JSON_SEARCH(' . $expression->sql . ", 'one', ?, NULL, ?) IS NOT NULL", [...$expression->bindings, '%' . $needle . '%', $path]);
    }
}

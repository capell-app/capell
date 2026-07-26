<?php

declare(strict_types=1);

namespace Capell\Core\Support\Database\QueryDialects;

use Capell\Core\Data\Database\SqlFragment;
use Capell\Core\Enums\Database\DatabaseDateOperation;

final class SqliteQueryDialect extends AbstractQueryDialect
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

        return new SqlFragment('INSTR(' . $sql . ', ?)', [...$expression->bindings, $caseInsensitive ? mb_strtolower($needle) : $needle]);
    }

    public function textRelevance(SqlFragment $expression, string $needle): SqlFragment
    {
        return $this->relevance($expression, $needle, 'INSTR(LOWER(' . $expression->sql . '), ?)', '1000.0');
    }

    public function date(DatabaseDateOperation $operation, SqlFragment $expression): SqlFragment
    {
        $month = "CASE strftime('%%m', %1\$s) WHEN '01' THEN 'Jan' WHEN '02' THEN 'Feb' WHEN '03' THEN 'Mar' WHEN '04' THEN 'Apr' WHEN '05' THEN 'May' WHEN '06' THEN 'Jun' WHEN '07' THEN 'Jul' WHEN '08' THEN 'Aug' WHEN '09' THEN 'Sep' WHEN '10' THEN 'Oct' WHEN '11' THEN 'Nov' WHEN '12' THEN 'Dec' END";
        $day = "CASE strftime('%%w', %1\$s) WHEN '0' THEN 'Sun' WHEN '1' THEN 'Mon' WHEN '2' THEN 'Tue' WHEN '3' THEN 'Wed' WHEN '4' THEN 'Thu' WHEN '5' THEN 'Fri' WHEN '6' THEN 'Sat' END";
        $sql = match ($operation) {
            DatabaseDateOperation::Year => "CAST(strftime('%%Y', %s) AS INTEGER)",
            DatabaseDateOperation::Month => "CAST(strftime('%%m', %s) AS INTEGER)",
            DatabaseDateOperation::Day => "CAST(strftime('%%d', %s) AS INTEGER)",
            DatabaseDateOperation::Hour => "CAST(strftime('%%H', %s) AS INTEGER)",
            DatabaseDateOperation::HourLabel => "strftime('%%H:00', %s)",
            DatabaseDateOperation::DayAbbreviation => $day,
            DatabaseDateOperation::DayMonthLabel => "strftime('%%d', %1\$s) || ' ' || " . $month,
            DatabaseDateOperation::MonthYearLabel => $month . " || ' ' || substr(strftime('%%Y', %1\$s), 3, 2)",
        };

        return new SqlFragment(sprintf($sql, $expression->sql), $expression->bindings);
    }

    public function elapsedSeconds(SqlFragment $start, SqlFragment $end): SqlFragment
    {
        return new SqlFragment(
            sprintf('CAST((julianday(%s) - julianday(%s)) * 86400 AS INTEGER)', $end->sql, $start->sql),
            $this->bindings([$end, $start]),
        );
    }

    public function jsonExtract(SqlFragment $expression, string $path): SqlFragment
    {
        return new SqlFragment('json_extract(' . $expression->sql . ', ?)', [...$expression->bindings, $path]);
    }

    public function jsonContains(SqlFragment $expression, mixed $value, string $path = '$'): SqlFragment
    {
        return new SqlFragment(
            "EXISTS (SELECT 1 FROM json_each({$expression->sql}, ?) AS capell_json_item CROSS JOIN (SELECT json(?) AS value) AS capell_json_target WHERE CASE WHEN capell_json_item.type IN ('integer', 'real') AND json_type(capell_json_target.value) IN ('integer', 'real') THEN CAST(capell_json_item.value AS NUMERIC) = CAST(json_extract(capell_json_target.value, '$') AS NUMERIC) WHEN capell_json_item.type = 'true' THEN json_type(capell_json_target.value) = 'true' WHEN capell_json_item.type = 'false' THEN json_type(capell_json_target.value) = 'false' ELSE json_quote(capell_json_item.value) = capell_json_target.value END)",
            [...$expression->bindings, $path, $this->jsonValue($value)],
        );
    }

    public function jsonSearch(SqlFragment $expression, string $needle, string $path = '$'): SqlFragment
    {
        if (preg_match('/^(.*)\\[\\*\\]\\.([A-Za-z_][A-Za-z0-9_]*)$/', $path, $matches) === 1) {
            $collectionPath = $matches[1] === '' ? '$' : $matches[1];

            return new SqlFragment(
                'EXISTS (SELECT 1 FROM json_each(' . $expression->sql . ', ?) WHERE CAST(json_extract(value, ?) AS TEXT) LIKE ?)',
                [...$expression->bindings, $collectionPath, '$.' . $matches[2], '%' . $needle . '%'],
            );
        }

        return new SqlFragment(
            'EXISTS (SELECT 1 FROM json_tree(' . $expression->sql . ', ?) WHERE CAST(value AS TEXT) LIKE ?)',
            [...$expression->bindings, $path, '%' . $needle . '%'],
        );
    }
}

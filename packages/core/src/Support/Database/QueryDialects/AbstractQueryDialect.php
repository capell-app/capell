<?php

declare(strict_types=1);

namespace Capell\Core\Support\Database\QueryDialects;

use Capell\Core\Contracts\Database\DatabaseQueryDialect;
use Capell\Core\Data\Database\SqlFragment;

abstract class AbstractQueryDialect implements DatabaseQueryDialect
{
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

        return new SqlFragment(
            sprintf(
                'CASE WHEN %1$s = ? THEN 0 WHEN %1$s LIKE ? THEN 1 WHEN %1$s LIKE ? THEN 2 ELSE 3 END + %2$s / %3$s',
                $normalized,
                $position,
                $divisor,
            ),
            [...$expression->bindings, $needle, $needle . '%', '%' . $needle . '%', $needle],
        );
    }
}

<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Database;

use Capell\Core\Data\Database\DatabaseFullTextSearch;
use Capell\Core\Data\Database\DatabaseSearchExpression;
use Capell\Core\Data\Database\SqlFragment;
use Capell\Core\Enums\Database\DatabaseDateOperation;

interface DatabaseQueryDialect
{
    public function concatenate(SqlFragment ...$expressions): SqlFragment;

    public function trimTrailingSlash(SqlFragment $expression): SqlFragment;

    public function textPosition(SqlFragment $expression, string $needle, bool $caseInsensitive = false): SqlFragment;

    public function textRelevance(SqlFragment $expression, string $needle): SqlFragment;

    /**
     * @param  non-empty-list<DatabaseSearchExpression>  $expressions
     */
    public function fullTextSearch(array $expressions, string $query, bool $native = false): DatabaseFullTextSearch;

    public function date(DatabaseDateOperation $operation, SqlFragment $expression): SqlFragment;

    public function elapsedSeconds(SqlFragment $start, SqlFragment $end): SqlFragment;

    public function jsonExtract(SqlFragment $expression, string $path): SqlFragment;

    public function jsonContains(SqlFragment $expression, mixed $value, string $path = '$'): SqlFragment;

    public function jsonSearch(SqlFragment $expression, SqlFragment $needle, string $path = '$'): SqlFragment;

    public function jsonExactSearch(SqlFragment $expression, SqlFragment $needle, string $path = '$'): SqlFragment;
}

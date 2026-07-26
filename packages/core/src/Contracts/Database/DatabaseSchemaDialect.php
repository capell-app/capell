<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Database;

use Capell\Core\Data\Database\DatabaseIndexDefinition;
use Capell\Core\Data\Database\SqlFragment;
use Capell\Core\Enums\Database\DatabaseCapability;
use Illuminate\Database\Connection;

interface DatabaseSchemaDialect
{
    public function supports(DatabaseCapability $capability, ?Connection $connection = null): bool;

    public function prefixedIndex(DatabaseIndexDefinition $index): SqlFragment;

    public function generatedColumn(string $table, string $column, string $expression, string $type): SqlFragment;

    public function hashColumn(string $table, string $column, string $sourceColumn): SqlFragment;

    public function jsonPathIndex(DatabaseIndexDefinition $index, string $column, string $path): ?SqlFragment;

    public function fullTextIndex(DatabaseIndexDefinition $index): ?SqlFragment;

    public function inspectGeneratedColumn(string $table, string $column): SqlFragment;
}

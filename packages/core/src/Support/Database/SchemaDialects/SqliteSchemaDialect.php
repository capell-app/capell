<?php

declare(strict_types=1);

namespace Capell\Core\Support\Database\SchemaDialects;

use Capell\Core\Contracts\Database\DatabaseSchemaDialect;
use Capell\Core\Data\Database\DatabaseIndexDefinition;
use Capell\Core\Data\Database\SqlFragment;
use Capell\Core\Enums\Database\DatabaseCapability;
use Illuminate\Database\Connection;

final class SqliteSchemaDialect extends AbstractSchemaDialect implements DatabaseSchemaDialect
{
    public function supports(DatabaseCapability $capability, ?Connection $connection = null): bool
    {
        return match ($capability) {
            DatabaseCapability::GeneratedColumn,
            DatabaseCapability::JsonPathIndex,
            DatabaseCapability::GeneratedColumnInspection => true,
            DatabaseCapability::PrefixIndex,
            DatabaseCapability::StoredGeneratedColumn,
            DatabaseCapability::HashGeneratedColumn,
            DatabaseCapability::FullTextIndex,
            DatabaseCapability::ForeignKeyDrop => false,
        };
    }

    public function prefixedIndex(DatabaseIndexDefinition $index): SqlFragment
    {
        return new SqlFragment(sprintf(
            '%s %s ON %s (%s)',
            $this->indexKeyword($index),
            $this->identifier($index->name, '"'),
            $this->identifier($index->table, '"'),
            implode(', ', array_map(fn (string $column): string => $this->identifier($column, '"'), $index->columns)),
        ));
    }

    public function generatedColumn(string $table, string $column, string $expression, string $type): SqlFragment
    {
        return new SqlFragment(sprintf(
            'ALTER TABLE %s ADD COLUMN %s %s GENERATED ALWAYS AS (%s) VIRTUAL',
            $this->identifier($table, '"'),
            $this->identifier($column, '"'),
            $this->columnType($type),
            $expression,
        ));
    }

    public function hashColumn(string $table, string $column, string $sourceColumn): SqlFragment
    {
        return new SqlFragment(sprintf(
            'ALTER TABLE %s ADD COLUMN %s CHAR(64)',
            $this->identifier($table, '"'),
            $this->identifier($column, '"'),
        ));
    }

    public function jsonPathIndex(DatabaseIndexDefinition $index, string $column, string $path): SqlFragment
    {
        return new SqlFragment(sprintf(
            '%s %s ON %s (json_extract(%s, %s))',
            $this->indexKeyword($index),
            $this->identifier($index->name, '"'),
            $this->identifier($index->table, '"'),
            $this->identifier($column, '"'),
            $this->jsonPathLiteral($path),
        ));
    }

    public function fullTextIndex(DatabaseIndexDefinition $index): ?SqlFragment
    {
        return null;
    }

    public function inspectGeneratedColumn(string $table, string $column): SqlFragment
    {
        return new SqlFragment(sprintf('PRAGMA table_xinfo(%s)', $this->identifier($table, '"')));
    }
}

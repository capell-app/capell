<?php

declare(strict_types=1);

namespace Capell\Core\Support\Database\SchemaDialects;

use Capell\Core\Contracts\Database\DatabaseSchemaDialect;
use Capell\Core\Data\Database\DatabaseIndexDefinition;
use Capell\Core\Data\Database\SqlFragment;
use Capell\Core\Enums\Database\DatabaseCapability;
use Illuminate\Database\Connection;
use Throwable;

final class PostgresSchemaDialect extends AbstractSchemaDialect implements DatabaseSchemaDialect
{
    public function supports(DatabaseCapability $capability, ?Connection $connection = null): bool
    {
        return match ($capability) {
            DatabaseCapability::GeneratedColumn,
            DatabaseCapability::StoredGeneratedColumn,
            DatabaseCapability::JsonPathIndex,
            DatabaseCapability::FullTextIndex,
            DatabaseCapability::ForeignKeyDrop,
            DatabaseCapability::GeneratedColumnInspection => true,
            DatabaseCapability::PrefixIndex,
            DatabaseCapability::HashGeneratedColumn => false,
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
            'ALTER TABLE %s ADD COLUMN %s %s GENERATED ALWAYS AS (%s) STORED',
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
            '%s %s ON %s ((jsonb_path_query_first(%s::jsonb, %s::jsonpath)))',
            $this->indexKeyword($index),
            $this->identifier($index->name, '"'),
            $this->identifier($index->table, '"'),
            $this->identifier($column, '"'),
            $this->jsonPathLiteral($path),
        ));
    }

    public function fullTextIndex(DatabaseIndexDefinition $index): SqlFragment
    {
        $columns = implode(" || ' ' || ", array_map(
            fn (string $column): string => sprintf("COALESCE(%s, '')", $this->identifier($column, '"')),
            $index->columns,
        ));

        return new SqlFragment(sprintf(
            "CREATE INDEX %s ON %s USING GIN (to_tsvector('simple', %s))",
            $this->identifier($index->name, '"'),
            $this->identifier($index->table, '"'),
            $columns,
        ));
    }

    public function hasCompatibleFullTextIndex(DatabaseIndexDefinition $index, Connection $connection): bool
    {
        if (! $this->supports(DatabaseCapability::FullTextIndex, $connection)) {
            return false;
        }

        try {
            $indexes = $connection->getSchemaBuilder()->getIndexes($index->table);
        } catch (Throwable) {
            return false;
        }

        foreach ($indexes as $existingIndex) {
            if (strtolower($existingIndex['name']) === strtolower($index->name)
                && strtolower($existingIndex['type']) === 'gin') {
                return true;
            }
        }

        return false;
    }

    public function inspectGeneratedColumn(string $table, string $column): SqlFragment
    {
        return new SqlFragment(
            'SELECT generation_expression FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?',
            [$table, $column],
        );
    }
}

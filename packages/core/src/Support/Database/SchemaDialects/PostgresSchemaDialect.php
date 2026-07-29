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
            $result = $connection->selectOne(
                <<<'SQL'
                    SELECT pg_get_indexdef(index_class.oid) AS definition
                    FROM pg_catalog.pg_class AS index_class
                    INNER JOIN pg_catalog.pg_index AS index_metadata
                        ON index_metadata.indexrelid = index_class.oid
                    INNER JOIN pg_catalog.pg_class AS table_class
                        ON table_class.oid = index_metadata.indrelid
                    INNER JOIN pg_catalog.pg_namespace AS table_namespace
                        ON table_namespace.oid = table_class.relnamespace
                    WHERE table_namespace.nspname = current_schema()
                        AND table_class.relname = ?
                        AND index_class.relname = ?
                        AND index_metadata.indisvalid = TRUE
                    LIMIT 1
                    SQL,
                [$this->physicalTableName($index->table, $connection), $index->name],
            );
        } catch (Throwable) {
            return false;
        }

        $definition = is_object($result) ? ($result->definition ?? null) : null;

        if (! is_string($definition)) {
            return false;
        }

        $expected = $this->fullTextIndex($index)->sql;

        return $this->normalizedGinDefinition($definition) === $this->normalizedGinDefinition($expected);
    }

    public function inspectGeneratedColumn(string $table, string $column, ?Connection $connection = null): SqlFragment
    {
        return new SqlFragment(
            'SELECT generation_expression FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?',
            [$this->physicalTableName($table, $connection), $column],
        );
    }

    public function hasConstraint(string $table, string $constraint, Connection $connection): bool
    {
        return $connection->query()
            ->fromRaw('information_schema.table_constraints')
            ->whereRaw('constraint_schema = current_schema()')
            ->where('table_name', $this->physicalTableName($table, $connection))
            ->where('constraint_name', $constraint)
            ->exists();
    }

    public function hasTrigger(string $trigger, Connection $connection): bool
    {
        return $connection->query()
            ->fromRaw('information_schema.triggers')
            ->whereRaw('trigger_schema = current_schema()')
            ->where('trigger_name', $trigger)
            ->exists();
    }

    private function normalizedGinDefinition(string $definition): ?string
    {
        $gin = stripos($definition, 'using gin');

        if ($gin === false) {
            return null;
        }

        $definition = substr($definition, $gin);
        $literals = [];
        $definition = preg_replace_callback(
            "/'(?:''|[^'])*'/",
            static function (array $matches) use (&$literals): string {
                $placeholder = "\x1D" . count($literals) . "\x1E";
                $literals[$placeholder] = $matches[0];

                return $placeholder;
            },
            $definition,
        );

        if (! is_string($definition)) {
            return null;
        }

        $definition = preg_replace('/::(?:regconfig|text)\b/i', '', $definition);

        if (! is_string($definition)) {
            return null;
        }

        $definition = strtolower(str_replace(['"', '(', ')', ' ', "\n", "\r", "\t"], '', $definition));

        return strtr($definition, $literals);
    }
}

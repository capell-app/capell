<?php

declare(strict_types=1);

namespace Capell\Core\Support\Database\SchemaDialects;

use Capell\Core\Contracts\Database\DatabaseSchemaDialect;
use Capell\Core\Data\Database\DatabaseIndexDefinition;
use Capell\Core\Data\Database\MySqlServerCapabilities;
use Capell\Core\Data\Database\SqlFragment;
use Capell\Core\Enums\Database\DatabaseCapability;
use Capell\Core\Enums\Database\DatabaseFamily;
use Illuminate\Database\Connection;
use WeakMap;

class MySqlSchemaDialect extends AbstractSchemaDialect implements DatabaseSchemaDialect
{
    /** @var WeakMap<Connection, MySqlServerCapabilities> */
    private WeakMap $serverCapabilities;

    public function __construct(private readonly DatabaseFamily $defaultFamily = DatabaseFamily::MySql)
    {
        $this->serverCapabilities = new WeakMap;
    }

    public function supports(DatabaseCapability $capability, ?Connection $connection = null): bool
    {
        if ($connection === null) {
            return match ($capability) {
                DatabaseCapability::PrefixIndex,
                DatabaseCapability::FullTextIndex,
                DatabaseCapability::ForeignKeyDrop,
                DatabaseCapability::GeneratedColumnInspection,
                DatabaseCapability::GeneratedColumn,
                DatabaseCapability::StoredGeneratedColumn => true,
                DatabaseCapability::HashGeneratedColumn,
                DatabaseCapability::JsonPathIndex => $this->defaultFamily === DatabaseFamily::MySql,
            };
        }

        $server = $this->serverCapabilities($connection);

        return match ($capability) {
            DatabaseCapability::PrefixIndex,
            DatabaseCapability::FullTextIndex,
            DatabaseCapability::ForeignKeyDrop,
            DatabaseCapability::GeneratedColumnInspection => true,
            DatabaseCapability::GeneratedColumn => $server->generatedColumns,
            DatabaseCapability::StoredGeneratedColumn => $server->storedGeneratedColumns,
            DatabaseCapability::HashGeneratedColumn => $server->family === DatabaseFamily::MySql,
            DatabaseCapability::JsonPathIndex => $server->functionalIndexes,
        };
    }

    public function prefixedIndex(DatabaseIndexDefinition $index): SqlFragment
    {
        $columns = array_map(function (string $column) use ($index): string {
            $sql = $this->identifier($column, '`');
            $prefix = $index->prefixLengths[$column] ?? null;

            return $prefix === null ? $sql : sprintf('%s(%d)', $sql, $prefix);
        }, $index->columns);

        return new SqlFragment(sprintf(
            '%s %s ON %s (%s)',
            $this->indexKeyword($index),
            $this->identifier($index->name, '`'),
            $this->identifier($index->table, '`'),
            implode(', ', $columns),
        ));
    }

    public function generatedColumn(string $table, string $column, string $expression, string $type): SqlFragment
    {
        return new SqlFragment(sprintf(
            'ALTER TABLE %s ADD COLUMN %s %s AS (%s) STORED',
            $this->identifier($table, '`'),
            $this->identifier($column, '`'),
            $this->columnType($type),
            $expression,
        ));
    }

    public function hashColumn(string $table, string $column, string $sourceColumn): SqlFragment
    {
        return $this->generatedColumn(
            $table,
            $column,
            sprintf('SHA2(%s, 256)', $this->identifier($sourceColumn, '`')),
            'CHAR(64)',
        );
    }

    public function jsonPathIndex(DatabaseIndexDefinition $index, string $column, string $path): ?SqlFragment
    {
        return new SqlFragment(sprintf(
            '%s %s ON %s ((JSON_UNQUOTE(JSON_EXTRACT(%s, ?))))',
            $this->indexKeyword($index),
            $this->identifier($index->name, '`'),
            $this->identifier($index->table, '`'),
            $this->identifier($column, '`'),
        ), [$path]);
    }

    public function fullTextIndex(DatabaseIndexDefinition $index): ?SqlFragment
    {
        return new SqlFragment(sprintf(
            'ALTER TABLE %s ADD FULLTEXT %s (%s)',
            $this->identifier($index->table, '`'),
            $this->identifier($index->name, '`'),
            implode(', ', array_map(fn (string $column): string => $this->identifier($column, '`'), $index->columns)),
        ));
    }

    public function inspectGeneratedColumn(string $table, string $column): SqlFragment
    {
        return new SqlFragment(
            'SELECT generation_expression FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            [$table, $column],
        );
    }

    public function serverCapabilities(Connection $connection): MySqlServerCapabilities
    {
        if (isset($this->serverCapabilities[$connection])) {
            return $this->serverCapabilities[$connection];
        }

        $version = $connection->getServerVersion();
        $family = str_contains(strtolower($version), 'mariadb')
            ? DatabaseFamily::MariaDb
            : DatabaseFamily::MySql;
        $numericVersion = preg_match('/\\d+(?:\\.\\d+){1,2}/', $version, $matches) === 1
            ? $matches[0]
            : '0.0.0';

        return $this->serverCapabilities[$connection] = new MySqlServerCapabilities(
            version: $version,
            family: $family,
            generatedColumns: version_compare($numericVersion, $family === DatabaseFamily::MariaDb ? '10.2.0' : '5.7.0', '>='),
            storedGeneratedColumns: version_compare($numericVersion, $family === DatabaseFamily::MariaDb ? '10.2.0' : '5.7.0', '>='),
            functionalIndexes: $family === DatabaseFamily::MySql && version_compare($numericVersion, '8.0.13', '>='),
        );
    }
}

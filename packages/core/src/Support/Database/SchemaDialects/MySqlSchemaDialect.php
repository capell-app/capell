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
use Override;
use PDO;
use Throwable;
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
        if (! $connection instanceof Connection) {
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
            '%s %s ON %s ((CAST(JSON_UNQUOTE(JSON_EXTRACT(%s, %s)) AS CHAR(191))))',
            $this->indexKeyword($index),
            $this->identifier($index->name, '`'),
            $this->identifier($index->table, '`'),
            $this->identifier($column, '`'),
            $this->jsonPathLiteral($path),
        ));
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

        $requiredColumns = $this->normalizedColumnSet($index->columns);

        foreach ($indexes as $existingIndex) {
            $type = $existingIndex['type'];
            $columns = $existingIndex['columns'];
            if (! is_string($type)) {
                continue;
            }

            if (strtolower($type) !== 'fulltext') {
                continue;
            }

            if (! is_array($columns)) {
                continue;
            }

            if ($this->normalizedColumnSet($columns) === $requiredColumns) {
                return true;
            }
        }

        return false;
    }

    public function inspectGeneratedColumn(string $table, string $column): SqlFragment
    {
        return new SqlFragment(
            'SELECT generation_expression FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            [$table, $column],
        );
    }

    public function hasConstraint(string $table, string $constraint, Connection $connection): bool
    {
        return $connection->table('information_schema.TABLE_CONSTRAINTS')
            ->whereRaw('TABLE_SCHEMA = DATABASE()')
            ->where('TABLE_NAME', $table)
            ->where('CONSTRAINT_NAME', $constraint)
            ->exists();
    }

    public function hasTrigger(string $trigger, Connection $connection): bool
    {
        return $connection->table('information_schema.TRIGGERS')
            ->whereRaw('TRIGGER_SCHEMA = DATABASE()')
            ->where('TRIGGER_NAME', $trigger)
            ->exists();
    }

    #[Override]
    public function hasForeignKeyReference(
        string $table,
        string $column,
        string $foreignTable,
        string $foreignColumn,
        Connection $connection,
    ): bool {
        return $connection->table('information_schema.KEY_COLUMN_USAGE')
            ->whereRaw('TABLE_SCHEMA = DATABASE()')
            ->where('TABLE_NAME', $table)
            ->where('COLUMN_NAME', $column)
            ->where('REFERENCED_TABLE_NAME', $foreignTable)
            ->where('REFERENCED_COLUMN_NAME', $foreignColumn)
            ->exists();
    }

    public function serverCapabilities(Connection $connection): MySqlServerCapabilities
    {
        if (isset($this->serverCapabilities[$connection])) {
            return $this->serverCapabilities[$connection];
        }

        $rawVersion = $connection->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION);
        $version = is_string($rawVersion) ? $rawVersion : $connection->getServerVersion();
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

    /**
     * @param  array<array-key, mixed>  $columns
     * @return list<string>
     */
    private function normalizedColumnSet(array $columns): array
    {
        $normalized = array_values(array_unique(array_map(
            static fn (mixed $column): string => mb_strtolower((string) $column),
            $columns,
        )));
        sort($normalized);

        return $normalized;
    }
}

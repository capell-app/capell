<?php

declare(strict_types=1);

namespace Capell\Core\Support\Database\SchemaDialects;

use Capell\Core\Contracts\Database\DatabaseSchemaDialect;
use Capell\Core\Contracts\Database\RepairsImplicitTimestampUpdates;
use Capell\Core\Data\Database\DatabaseIndexDefinition;
use Capell\Core\Data\Database\MySqlServerCapabilities;
use Capell\Core\Data\Database\SqlFragment;
use Capell\Core\Enums\Database\DatabaseCapability;
use Capell\Core\Enums\Database\DatabaseFamily;
use Illuminate\Database\Connection;
use Override;
use PDO;
use RuntimeException;
use WeakMap;

final class MySqlSchemaDialect extends AbstractSchemaDialect implements DatabaseSchemaDialect, RepairsImplicitTimestampUpdates
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

    public function jsonPathIndex(DatabaseIndexDefinition $index, string $column, string $path): SqlFragment
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

    public function inspectGeneratedColumn(string $table, string $column, ?Connection $connection = null): SqlFragment
    {
        return new SqlFragment(
            'SELECT generation_expression FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            [$this->physicalTableName($table, $connection), $column],
        );
    }

    public function hasConstraint(string $table, string $constraint, Connection $connection): bool
    {
        return $connection->query()
            ->fromRaw('information_schema.TABLE_CONSTRAINTS')
            ->whereRaw('TABLE_SCHEMA = DATABASE()')
            ->where('TABLE_NAME', $this->physicalTableName($table, $connection))
            ->where('CONSTRAINT_NAME', $constraint)
            ->exists();
    }

    public function hasTrigger(string $trigger, Connection $connection): bool
    {
        return $connection->query()
            ->fromRaw('information_schema.TRIGGERS')
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
        return $connection->query()
            ->fromRaw('information_schema.KEY_COLUMN_USAGE')
            ->whereRaw('TABLE_SCHEMA = DATABASE()')
            ->where('TABLE_NAME', $this->physicalTableName($table, $connection))
            ->where('COLUMN_NAME', $column)
            ->where('REFERENCED_TABLE_NAME', $this->physicalTableName($foreignTable, $connection))
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

    #[Override]
    public function dropImplicitTimestampUpdate(string $table, string $column, Connection $connection): void
    {
        /** @var object{COLUMN_TYPE: string, IS_NULLABLE: string, COLUMN_DEFAULT: ?string, COLUMN_COMMENT: string, EXTRA: string}|null $metadata */
        $metadata = $connection->selectOne(
            'SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_COMMENT, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$connection->getDatabaseName(), $this->physicalTableName($table, $connection), $column],
        );

        if ($metadata === null
            || ! str_contains(strtolower($metadata->EXTRA), 'on update current_timestamp')
            || preg_match('/^timestamp(?:\([0-6]\))?$/i', $metadata->COLUMN_TYPE) !== 1) {
            return;
        }

        $nullable = $metadata->IS_NULLABLE === 'YES';
        $default = $metadata->COLUMN_DEFAULT;
        $defaultClause = $nullable ? ' DEFAULT NULL' : '';

        // MariaDB represents SQL NULL as the unquoted string "NULL".
        if ($default !== null && strtoupper($default) !== 'NULL') {
            if (preg_match('/^current_timestamp(?:\([0-6]?\))?$/i', $default) === 1) {
                $defaultClause = ' DEFAULT ' . $default;
            } else {
                // MariaDB quotes literal defaults in its catalogue; MySQL does not.
                if (str_starts_with($default, "'") && str_ends_with($default, "'")) {
                    $default = str_replace("''", "'", substr($default, 1, -1));
                }

                // Expressions must never be rewritten as quoted string literals.
                throw_unless(
                    preg_match('/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?)?$/', $default) === 1,
                    RuntimeException::class,
                    sprintf('Cannot remove implicit timestamp updates from [%s.%s]: unsupported TIMESTAMP default [%s].', $table, $column, $default),
                );

                $defaultClause = ' DEFAULT ' . $connection->getPdo()->quote($default);
            }
        }

        $grammar = $connection->getQueryGrammar();
        // Preserve the insert default explicitly so legacy MariaDB cannot add
        // ON UPDATE back when explicit_defaults_for_timestamp is disabled.
        $connection->statement(sprintf(
            'ALTER TABLE %s MODIFY COLUMN %s %s %s%s COMMENT %s',
            $grammar->wrapTable($table),
            $grammar->wrap($column),
            strtoupper($metadata->COLUMN_TYPE),
            $nullable ? 'NULL' : 'NOT NULL',
            $defaultClause,
            $connection->getPdo()->quote($metadata->COLUMN_COMMENT),
        ));
    }
}

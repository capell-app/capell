<?php

declare(strict_types=1);

namespace Capell\Core\Support\Migration;

use Capell\Core\Support\TableName;
use Composer\InstalledVersions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Compares a live table with the table a Capell create migration produces, so a table that
 * lost its migrations row can be told apart from another application's table of the same name.
 */
final class TableShape
{
    /**
     * Tables a migration renamed or replaced: legacy base name => [current base name, historic creating migration].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const array LEGACY_TABLES = [
        'asset_relations' => ['asset_attachments', '2026_05_10_190832_15_create_asset_relations_table'],
    ];

    /** @var array<string, array<string, array<string, array{type: string, nullable: bool, primary: bool}>>|null> */
    private static array $probed = [];

    /** True when every column the creating migration defines exists live with the same type family, nullability and primary-key membership (later migrations may add columns). */
    public static function matches(string $baseTable, string $migration): bool
    {
        return self::expected($baseTable, $migration) !== null && self::differences($baseTable, $migration) === [];
    }

    /** @return list<string> Human-readable differences; a missing migration file reports one entry. */
    public static function differences(string $baseTable, string $migration): array
    {
        $expected = self::expected($baseTable, $migration);
        if ($expected === null) {
            return ['no Capell migration defines this table'];
        }

        $live = self::describe(TableName::resolve($baseTable));
        $differences = [];
        foreach ($expected as $name => $column) {
            if (! isset($live[$name])) {
                $differences[] = $name . ' is missing';

                continue;
            }

            foreach (['type' => 'type', 'nullable' => 'nullability', 'primary' => 'primary key'] as $key => $label) {
                if ($live[$name][$key] !== $column[$key]) {
                    $differences[] = $name . ' differs in ' . $label;
                }
            }
        }

        return $differences;
    }

    /** @return array<string, array{type: string, nullable: bool, primary: bool}>|null null when no migration file defines the table */
    public static function expected(string $baseTable, string $migration): ?array
    {
        $table = $baseTable;
        $path = self::locate($migration);
        if ($path === null && isset(self::LEGACY_TABLES[$baseTable])) {
            $table = self::LEGACY_TABLES[$baseTable][0];
            $path = self::locate('create_' . $table . '_table');
        }

        if ($path === null) {
            return null;
        }

        return self::probe($path)[TableName::resolve($table)] ?? null;
    }

    public static function locate(string $migration): ?string
    {
        $name = self::withoutTimestamp($migration);
        foreach (self::directories() as $directory) {
            foreach (glob($directory . '/*.php') ?: [] as $path) {
                if (self::withoutTimestamp(basename($path, '.php')) === $name) {
                    return $path;
                }
            }
        }

        return null;
    }

    /** The migration that creates a base table, or the historic one for a renamed table. */
    public static function migrationFor(string $baseTable): ?string
    {
        if (isset(self::LEGACY_TABLES[$baseTable])) {
            return self::LEGACY_TABLES[$baseTable][1];
        }

        foreach (resolve(MigrationRootRegistry::class)->all() as $root) {
            foreach ($root['tables'] as $migration => $names) {
                if (in_array($baseTable, (array) $names, true)) {
                    return $migration;
                }
            }
        }

        foreach (self::packageDirectories() as $directory) {
            foreach (glob($directory . '/*.php') ?: [] as $path) {
                if (in_array($baseTable, self::createdBaseTables($path), true)) {
                    return basename($path, '.php');
                }
            }
        }

        return null;
    }

    /** @return list<string> Every base table a Capell migration or registered package root creates. */
    public static function allBaseTables(): array
    {
        $tables = [];
        foreach (self::packageDirectories() as $directory) {
            foreach (glob($directory . '/*.php') ?: [] as $path) {
                array_push($tables, ...self::createdBaseTables($path));
            }
        }

        foreach (resolve(MigrationRootRegistry::class)->all() as $root) {
            foreach ($root['tables'] as $names) {
                array_push($tables, ...array_values((array) $names));
            }
        }

        return array_values(array_unique($tables));
    }

    /** @return array<string, array{type: string, nullable: bool, primary: bool}> */
    private static function describe(string $table): array
    {
        $primary = [];
        foreach (Schema::getIndexes($table) as $index) {
            if ($index['primary']) {
                array_push($primary, ...array_map(strtolower(...), $index['columns']));
            }
        }

        $columns = [];
        foreach (Schema::getColumns($table) as $column) {
            $name = strtolower((string) $column['name']);
            $columns[$name] = [
                'type' => self::family((string) $column['type_name']),
                'nullable' => (bool) $column['nullable'],
                'primary' => in_array($name, $primary, true),
            ];
        }

        return $columns;
    }

    /** Collapses driver-specific type names into families that compare across SQLite, MySQL, MariaDB and PostgreSQL. */
    private static function family(string $type): string
    {
        $type = strtolower((string) preg_replace('/\(.*$/', '', trim($type)));

        return match (true) {
            in_array($type, ['int', 'integer', 'tinyint', 'smallint', 'mediumint', 'bigint', 'int2', 'int4', 'int8', 'bool', 'boolean', 'serial', 'bigserial'], true) => 'integer',
            in_array($type, ['decimal', 'numeric', 'float', 'double', 'real', 'float4', 'float8', 'double precision'], true) => 'number',
            in_array($type, ['datetime', 'timestamp', 'timestamptz', 'date', 'time', 'timetz', 'year', 'timestamp without time zone', 'timestamp with time zone'], true) => 'temporal',
            in_array($type, ['blob', 'tinyblob', 'mediumblob', 'longblob', 'bytea', 'binary', 'varbinary'], true) => 'binary',
            default => 'text',
        };
    }

    /** @return list<string> */
    private static function createdBaseTables(string $path): array
    {
        $source = file_get_contents($path);
        if ($source === false) {
            return [];
        }

        preg_match_all("~Schema::create\\(TableName::resolve\\('([^']+)'\\)~", $source, $matches);

        return $matches[1];
    }

    /**
     * Migration directories of every installed Capell package (resolved through Composer, so a vendor
     * layout works as well as the monorepo), this package, and every registered migration root.
     *
     * @return list<string>
     */
    private static function packageDirectories(): array
    {
        $directories = [CoreSchemaMigrations::path()];
        foreach (InstalledVersions::getInstalledPackages() as $package) {
            if (! str_starts_with($package, 'capell-app/')) {
                continue;
            }

            $path = InstalledVersions::getInstallPath($package);
            if ($path !== null && is_dir($path . '/database/migrations')) {
                $directories[] = $path . '/database/migrations';
            }
        }

        $unique = [];
        foreach ($directories as $directory) {
            $unique[realpath($directory) ?: $directory] = $directory;
        }

        return array_values($unique);
    }

    /** @return list<string> */
    private static function directories(): array
    {
        return [
            ...self::packageDirectories(),
            ...array_map(static fn (array $root): string => rtrim($root['root'], DIRECTORY_SEPARATOR), resolve(MigrationRootRegistry::class)->all()),
        ];
    }

    /** @return array<string, array<string, array{type: string, nullable: bool, primary: bool}>> physical table => column shape, from running the migration on a scratch database */
    private static function probe(string $path): array
    {
        $key = $path . '|' . json_encode([config('capell.database.table_prefix'), config('capell.database.table_aliases')]);
        if (array_key_exists($key, self::$probed)) {
            return self::$probed[$key] ?? [];
        }

        $connection = 'capell_table_shape_probe';
        $previous = DB::getDefaultConnection();
        config()->set('database.connections.' . $connection, ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]);
        DB::purge($connection);
        DB::setDefaultConnection($connection);

        try {
            $migration = require $path;
            $migration->up();
            $tables = [];
            foreach (Schema::getTables() as $table) {
                $tables[$table['name']] = self::describe($table['name']);
            }

            return self::$probed[$key] = $tables;
        } catch (Throwable) {
            self::$probed[$key] = null;

            return [];
        } finally {
            DB::setDefaultConnection($previous);
            DB::purge($connection);
        }
    }

    private static function withoutTimestamp(string $migration): string
    {
        return preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}(?:_\d{2})?_/', '', $migration) ?? $migration;
    }
}

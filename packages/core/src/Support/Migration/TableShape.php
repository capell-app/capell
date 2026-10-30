<?php

declare(strict_types=1);

namespace Capell\Core\Support\Migration;

use Capell\Core\Support\TableName;
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

    /** @var array<string, array<string, list<string>>|null> */
    private static array $probed = [];

    /** True when the live table has every column the creating migration defines (later migrations may add more). */
    public static function matches(string $baseTable, string $migration): bool
    {
        $expected = self::expectedColumns($baseTable, $migration);
        if ($expected === null) {
            return false;
        }

        $live = array_map(strtolower(...), Schema::getColumnListing(TableName::resolve($baseTable)));

        return array_diff($expected, $live) === [];
    }

    /** @return list<string> */
    public static function missingColumns(string $baseTable, string $migration): array
    {
        $expected = self::expectedColumns($baseTable, $migration) ?? [];
        $live = array_map(strtolower(...), Schema::getColumnListing(TableName::resolve($baseTable)));

        return array_values(array_diff($expected, $live));
    }

    /** @return list<string>|null null when no migration file defines the table */
    public static function expectedColumns(string $baseTable, string $migration): ?array
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

        foreach (self::coreDirectories() as $directory) {
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
        foreach (self::coreDirectories() as $directory) {
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

    /** @return list<string> */
    private static function coreDirectories(): array
    {
        return glob(dirname(__DIR__, 4) . '/*/database/migrations', GLOB_ONLYDIR) ?: [];
    }

    /** @return list<string> */
    private static function directories(): array
    {
        return [
            ...self::coreDirectories(),
            ...array_map(static fn (array $root): string => rtrim($root['root'], DIRECTORY_SEPARATOR), resolve(MigrationRootRegistry::class)->all()),
        ];
    }

    /** @return array<string, list<string>> physical table => lower-case column names, from running the migration on a scratch database */
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
                $tables[$table['name']] = array_values(array_map(static fn (array $column): string => strtolower((string) $column['name']), Schema::getColumns($table['name'])));
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

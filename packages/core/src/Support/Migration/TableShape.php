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

    /** @var array<string, array{shape: array<string, array{type: string, nullable: bool, primary: bool}>|null, failure: string|null}> */
    private static array $probed = [];

    /** True when every column the creating migration defines exists live with the same type family, nullability and primary-key membership (later migrations may add columns). */
    public static function matches(string $baseTable, string $migration): bool
    {
        return self::expected($baseTable, $migration) !== null && self::differences($baseTable, $migration) === [];
    }

    /** @return list<string> Human-readable differences; a missing migration file reports one entry. */
    public static function differences(string $baseTable, string $migration): array
    {
        $result = self::resolveExpected($baseTable, $migration);
        $expected = $result['shape'];
        if ($expected === null) {
            return [$result['failure'] ?? 'no Capell migration defines this table'];
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

    /** @return array<string, array{type: string, nullable: bool, primary: bool}>|null null when the shape cannot be built */
    public static function expected(string $baseTable, string $migration): ?array
    {
        return self::resolveExpected($baseTable, $migration)['shape'];
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

    /**
     * The table as it stands after the create migration and every recorded migration that mentions it,
     * built on a scratch SQLite database so later alter migrations count and the live database is untouched.
     *
     * @return array{shape: array<string, array{type: string, nullable: bool, primary: bool}>|null, failure: string|null}
     */
    private static function resolveExpected(string $baseTable, string $migration): array
    {
        $table = $baseTable;
        $create = self::locate($migration);
        if ($create === null && isset(self::LEGACY_TABLES[$baseTable])) {
            $table = self::LEGACY_TABLES[$baseTable][0];
            $create = self::locate('create_' . $table . '_table');
        }

        if ($create === null) {
            return ['shape' => null, 'failure' => null];
        }

        $ran = [];
        $repository = resolve('migrator')->getRepository();
        if ($repository->repositoryExists()) {
            foreach ($repository->getRan() as $recorded) {
                $ran[self::withoutTimestamp((string) $recorded)] = true;
            }
        }

        $files = [];
        foreach (self::directories() as $directory) {
            foreach (glob($directory . '/*.php') ?: [] as $path) {
                $name = basename($path, '.php');
                $source = (string) file_get_contents($path);
                if (($path === $create || isset($ran[self::withoutTimestamp($name)])) && str_contains($source, "'" . $table . "'")) {
                    $files[$name] = $path;
                }
            }
        }

        $files[basename($create, '.php')] = $create;
        ksort($files);

        return self::probe(array_values($files), TableName::resolve($table));
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

    /**
     * @param  list<string>  $files  Migration files to run, oldest first.
     * @return array{shape: array<string, array{type: string, nullable: bool, primary: bool}>|null, failure: string|null}
     */
    private static function probe(array $files, string $table): array
    {
        $key = $table . '|' . implode(',', $files) . '|' . json_encode([config('capell.database.table_prefix'), config('capell.database.table_aliases')]);
        if (isset(self::$probed[$key])) {
            return self::$probed[$key];
        }

        $connection = 'capell_table_shape_probe';
        $previous = DB::getDefaultConnection();
        config()->set('database.connections.' . $connection, ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]);
        DB::purge($connection);
        DB::setDefaultConnection($connection);

        $current = '';

        try {
            // Migrations check ownership through the ledger, so give the scratch database its own.
            $repository = resolve('migrator')->getRepository();
            $repository->createRepository();
            foreach ($files as $path) {
                $current = basename($path, '.php');
                (require $path)->up();
                $repository->log($current, 1);
            }

            return self::$probed[$key] = Schema::hasTable($table)
                ? ['shape' => self::describe($table), 'failure' => null]
                : ['shape' => null, 'failure' => 'the migrations that define this table produce no table on SQLite'];
        } catch (Throwable $throwable) {
            return self::$probed[$key] = ['shape' => null, 'failure' => sprintf('migration %s cannot run on SQLite (%s), so the table cannot be compared', $current, $throwable->getMessage())];
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

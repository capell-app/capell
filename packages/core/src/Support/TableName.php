<?php

declare(strict_types=1);

namespace Capell\Core\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class TableName
{
    public static function existsAndIsOwned(string $name, string $migration): bool
    {
        $table = self::resolve($name);
        if (! Schema::hasTable($table)) {
            return false;
        }

        $repository = resolve('migrator')->getRepository();
        if (! $repository->repositoryExists() || ! in_array($migration, $repository->getRan(), true)) {
            throw new RuntimeException(sprintf('Table %s already exists without its Capell creation migration. Choose a table alias or prefix before installing.', $table));
        }

        return true;
    }

    public static function externalExistsAndIsOwned(string $table, string $migration): bool
    {
        if (! Schema::hasTable($table)) {
            return false;
        }

        $repository = resolve('migrator')->getRepository();
        $ran = $repository->repositoryExists() ? $repository->getRan() : [];
        foreach ($ran as $recorded) {
            if ($recorded === $migration || str_ends_with((string) $recorded, '_' . $migration)) {
                return true;
            }
        }

        throw new RuntimeException(sprintf('Table %s already exists without its package creation migration. Choose a table alias or prefix before installing.', $table));
    }

    public static function base(string $table): string
    {
        $aliases = config('capell.database.table_aliases', []);
        if (is_array($aliases)) {
            foreach ($aliases as $base => $alias) {
                if (is_string($base) && $alias === $table) {
                    return $base;
                }
            }
        }

        $prefix = (string) config('capell.database.table_prefix', '');

        return $prefix !== '' && str_starts_with($table, $prefix) ? substr($table, strlen($prefix)) : $table;
    }

    /** @param string|list<string> $columns */
    public static function index(string $baseTable, string|array $columns, string $type): string
    {
        $connection = DB::connection();
        $resolved = self::resolve($baseTable);
        $table = $connection->getConfig('prefix_indexes') ? $connection->getTablePrefix() . $resolved : $resolved;
        $name = str_replace(['-', '.'], '_', strtolower($table . '_' . implode('_', (array) $columns) . '_' . $type));

        if (strlen($name) <= 63 || ($resolved === $baseTable && $connection->getTablePrefix() === '')) {
            return $name;
        }

        return substr($name, 0, 54) . '_' . substr(hash('sha256', $name), 0, 8);
    }

    public static function namedIndex(string $baseTable, string $legacyName): string
    {
        $resolved = self::resolve($baseTable);
        if ($resolved === $baseTable) {
            return $legacyName;
        }

        $suffix = str_starts_with($legacyName, $baseTable . '_') ? substr($legacyName, strlen($baseTable)) : '_' . $legacyName;
        $name = strtolower(str_replace(['-', '.'], '_', $resolved . $suffix));

        return strlen($name) <= 63 ? $name : substr($name, 0, 54) . '_' . substr(hash('sha256', $name), 0, 8);
    }

    public static function resolve(string $name): string
    {
        $aliases = config('capell.database.table_aliases', []);
        $resolved = is_array($aliases) && isset($aliases[$name]) && is_string($aliases[$name])
            ? $aliases[$name]
            : config('capell.database.table_prefix', '') . $name;

        throw_if(preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,62}$/D', $resolved) !== 1, RuntimeException::class, 'Invalid database table name: ' . $resolved);

        return $resolved;
    }

    public static function migrationRecorded(string $migration): bool
    {
        $repository = resolve('migrator')->getRepository();

        return $repository->repositoryExists() && in_array($migration, $repository->getRan(), true);
    }
}

<?php

declare(strict_types=1);

namespace Capell\Core\Support;

use Capell\Core\Actions\Install\AssertTableOwnershipAction;
use Capell\Core\Exceptions\ForeignTableException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class TableName
{
    /** Spatie's tag model is an optional integration, so it is referenced by name. */
    public const string SPATIE_TAG_MODEL = 'Spatie\\Tags\\Tag';

    /** PostgreSQL's identifier cap; MySQL and MariaDB allow 64. */
    public const int MAX_IDENTIFIER_LENGTH = 63;

    /** @throws ForeignTableException when the table exists without its creating migration being recorded */
    public static function existsAndIsOwned(string $name, string $migration): bool
    {
        return AssertTableOwnershipAction::run($name, $migration);
    }

    /** @throws ForeignTableException when the table exists without its creating migration being recorded */
    public static function externalExistsAndIsOwned(string $table, string $migration, ?string $configKey = null): bool
    {
        return AssertTableOwnershipAction::run($table, $migration, $configKey ?? self::externalConfigKey($table));
    }

    /** The real config key that names an externally owned table, so a message can point at it. */
    public static function externalConfigKey(string $table): string
    {
        $candidates = [
            'permission.table_names' => config('permission.table_names'),
            'capell.database.external_tables' => config('capell.database.external_tables'),
        ];
        foreach ($candidates as $prefix => $names) {
            if (is_array($names) && ($key = array_search($table, $names, true)) !== false) {
                return $prefix . '.' . $key;
            }
        }

        foreach (['settings.repositories.database.table', 'activitylog.table_name', 'tags.taggable.table_name'] as $key) {
            if (config($key) === $table) {
                return $key;
            }
        }

        return 'capell.database.external_tables.' . $table;
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
        throw_if(preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name) !== 1, InvalidArgumentException::class, 'Invalid base table name: ' . $name);

        $aliases = config('capell.database.table_aliases', []);
        $alias = is_array($aliases) ? ($aliases[$name] ?? null) : null;
        throw_if($alias !== null && ! is_string($alias), RuntimeException::class, 'Invalid database table alias for ' . $name);

        $prefix = config('capell.database.table_prefix', '');
        throw_unless(is_string($prefix), RuntimeException::class, 'Invalid database table prefix: capell.database.table_prefix must be a string.');

        $resolved = $alias ?? $prefix . $name;

        throw_if(preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,' . (self::MAX_IDENTIFIER_LENGTH - 1) . '}$/D', $resolved) !== 1, RuntimeException::class, 'Invalid database table name: ' . $resolved);

        return $resolved;
    }

    public static function migrationRecorded(string $migration): bool
    {
        $repository = resolve('migrator')->getRepository();

        if (! $repository->repositoryExists()) {
            return false;
        }

        $name = preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}(?:_\d{2})?_/', '', $migration) ?? $migration;
        foreach ($repository->getRan() as $recorded) {
            if ($recorded === $name || str_ends_with((string) $recorded, '_' . $name)) {
                return true;
            }
        }

        return false;
    }
}

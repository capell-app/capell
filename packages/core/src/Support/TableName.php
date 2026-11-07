<?php

declare(strict_types=1);

namespace Capell\Core\Support;

use Capell\Core\Actions\Install\AssertTableOwnershipAction;
use Capell\Core\Actions\Install\PrepareVendorTableMigrationAction;
use Capell\Core\Exceptions\ForeignTableException;
use Capell\Core\Support\Migration\TableShape;
use Composer\InstalledVersions;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\DatabaseMigrationRepository;
use Illuminate\Database\Migrations\MigrationRepositoryInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class TableName
{
    /** Spatie's tag model is an optional integration, so it is referenced by name. */
    public const string SPATIE_TAG_MODEL = 'Spatie\\Tags\\Tag';

    /** PostgreSQL's identifier cap; MySQL and MariaDB allow 64. */
    public const int MAX_IDENTIFIER_LENGTH = 63;

    private const string LEDGER_BINDING = 'capell.table-name.ledger';

    /** @var array<string, string> Original source SHA-256 for supported historical table renames. */
    private const array LEGACY_MIGRATION_SHA256 = [
        '2026_05_10_190832_15_create_asset_relations_table' => '70d0ee9797dd00936cf83dd4b1cca7cecc3be28bdeccb01b1196db50fca922a5',
    ];

    /**
     * Runs a callback reading the migration ledger from another repository (the table-shape probe's scratch database).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function usingLedger(MigrationRepositoryInterface $ledger, callable $callback): mixed
    {
        $app = app();
        $current = $app->bound(self::LEDGER_BINDING) ? $app->make(self::LEDGER_BINDING) : null;
        $previous = $current instanceof MigrationRepositoryInterface ? $current : null;
        $app->instance(self::LEDGER_BINDING, $ledger);

        try {
            return $callback();
        } finally {
            if (! $previous instanceof MigrationRepositoryInterface) {
                $app->forgetInstance(self::LEDGER_BINDING);
                unset($app[self::LEDGER_BINDING]);
            } else {
                $app->instance(self::LEDGER_BINDING, $previous);
            }
        }
    }

    /** @throws ForeignTableException when the table exists without its creating migration being recorded */
    public static function existsAndIsOwned(string $name, string $migration, ?string $connection = null): bool
    {
        return AssertTableOwnershipAction::run($name, $migration, null, $connection);
    }

    /** @throws ForeignTableException when the table exists without its creating migration being recorded */
    public static function externalExistsAndIsOwned(string $table, string $migration, ?string $configKey = null, ?string $connection = null): bool
    {
        return AssertTableOwnershipAction::run($table, $migration, $configKey ?? self::externalConfigKey($table), $connection);
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

    /**
     * Exact known source names identify managed migrations. A different publication timestamp
     * additionally requires the recorded host file to match that source byte for byte.
     */
    public static function migrationRecorded(string $migration, ?string $connection = null): bool
    {
        $repository = self::ledger($connection);

        if (! $repository->repositoryExists() || basename($migration) !== $migration) {
            return false;
        }

        $sources = self::migrationSources($migration);
        $legacy = self::legacyMigration($migration);
        if ($sources === [] && $legacy === null) {
            return false;
        }

        $name = self::withoutTimestamp($migration);
        foreach ($repository->getRan() as $recorded) {
            if (basename($recorded) !== $recorded) {
                continue;
            }

            if (self::withoutTimestamp($recorded) !== $name) {
                continue;
            }

            $effective = self::effectiveMigration($recorded);
            if ($legacy !== null) {
                if ($recorded === $legacy && $effective === null) {
                    return true;
                }

                $historicalHash = self::LEGACY_MIGRATION_SHA256[$legacy] ?? null;
                $effectiveHash = $effective === null ? false : hash_file('sha256', $effective);
                if ($historicalHash !== null && is_string($effectiveHash) && hash_equals($historicalHash, $effectiveHash)) {
                    return true;
                }
            }

            foreach ($sources as $source) {
                // A host file with the same name overrides registered paths in Laravel's migrator.
                // An exact ledger identity is sufficient only when no effective file contradicts it.
                if ($effective === null ? $recorded === self::sourceName($source) : self::matchesPublishedSource($source, $effective)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function legacyMigration(string $migration): ?string
    {
        foreach (TableShape::LEGACY_TABLES as [, $canonical]) {
            if (self::withoutTimestamp($canonical) === self::withoutTimestamp($migration)) {
                return $canonical;
            }
        }

        return null;
    }

    private static function ledger(?string $connection): MigrationRepositoryInterface
    {
        if ($connection === null && app()->bound(self::LEDGER_BINDING)) {
            return resolve(self::LEDGER_BINDING);
        }

        // The migrator repository may be pinned to another connection by a previous command.
        $migrations = config('database.migrations');
        $table = is_array($migrations) ? ($migrations['table'] ?? 'migrations') : ($migrations ?? 'migrations');
        $repository = new DatabaseMigrationRepository(resolve(DatabaseManager::class), (string) $table);
        $repository->setSource($connection ?? DB::getDefaultConnection());

        return $repository;
    }

    /** @return list<string> */
    private static function migrationSources(string $migration): array
    {
        $sources = TableShape::sourcesFor($migration);

        // These integrations own external tables; an arbitrary host migration is never a source.
        $packages = [
            'create_settings_table' => 'spatie/laravel-settings',
            'create_permission_tables' => 'spatie/laravel-permission',
            'create_activity_log_table' => 'spatie/laravel-activitylog',
            'create_media_table' => 'spatie/laravel-medialibrary',
            'create_tag_tables' => 'spatie/laravel-tags',
        ];
        $name = self::withoutTimestamp($migration);
        $package = $packages[$name] ?? null;
        if ($package === null || ! InstalledVersions::isInstalled($package)) {
            return $sources;
        }

        $path = InstalledVersions::getInstallPath($package);
        $stub = $path . '/database/migrations/' . $name . '.php.stub';

        $root = realpath($path . '/database/migrations');
        $target = realpath($stub);
        if ($root !== false && $target !== false && str_starts_with($target, $root . DIRECTORY_SEPARATOR)) {
            $sources[] = $stub;
        }

        return array_values(array_unique($sources));
    }

    private static function effectiveMigration(string $recorded): ?string
    {
        $migrator = resolve('migrator');
        // Match Laravel's execution order: the host directory is last and wins duplicate names.
        $files = $migrator->getMigrationFiles([...$migrator->paths(), database_path('migrations')]);

        return $files[$recorded] ?? null;
    }

    private static function sourceName(string $source): string
    {
        return str_ends_with($source, '.php.stub') ? basename($source, '.php.stub') : basename($source, '.php');
    }

    private static function matchesPublishedSource(string $source, string $published): bool
    {
        $sourceHash = hash_file('sha256', $source);
        $publishedHash = hash_file('sha256', $published);
        if (! is_string($sourceHash) || ! is_string($publishedHash)) {
            return false;
        }

        if (hash_equals($sourceHash, $publishedHash)) {
            return true;
        }

        // Capell deterministically configures these vendor stubs when publishing them.
        if (! str_ends_with($source, '.php.stub') || ! in_array(self::sourceName($source), ['create_media_table', 'create_tag_tables'], true)) {
            return false;
        }

        $contents = file_get_contents($source);

        if (! is_string($contents)) {
            return false;
        }

        $prepared = PrepareVendorTableMigrationAction::run(self::sourceName($source), $contents);

        return is_string($prepared) && hash_equals(hash('sha256', $prepared), $publishedHash);
    }

    private static function withoutTimestamp(string $migration): string
    {
        return preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}(?:_\d{2})?_/', '', $migration) ?? $migration;
    }
}

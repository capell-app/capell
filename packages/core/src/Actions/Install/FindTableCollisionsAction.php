<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Data\Install\TableCollisionData;
use Capell\Core\Data\Manifest\ExtensionLegacyTableData;
use Capell\Core\Data\PackageData;
use Capell\Core\Support\Install\MissingSqliteDatabase;
use Capell\Core\Support\Migration\MigrationRootRegistry;
use Capell\Core\Support\Packages\PackageRoleResolver;
use Capell\Core\Support\TableName;
use Composer\InstalledVersions;
use Illuminate\Support\Facades\Schema;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

final class FindTableCollisionsAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  list<string>  $packages
     * @param  list<array{name: string, configKey: string, migration: string}>  $declaredLegacyTables  Declarations captured with the plan
     * @return list<TableCollisionData>
     */
    public function handle(array $packages, array $declaredLegacyTables = []): array
    {
        if (MissingSqliteDatabase::path() !== null) {
            return [];
        }

        $adopted = config()->array('capell.database.adopted_tables', []);

        return array_values(array_filter($this->planned($packages, $declaredLegacyTables), static function (TableCollisionData $table) use ($adopted): bool {
            if (! Schema::hasTable($table->table) || in_array($table->name, $adopted, true)) {
                return false;
            }

            return ! TableName::migrationRecorded($table->migration);
        }));
    }

    /**
     * @param  list<string>  $packages
     * @param  list<array{name: string, configKey: string, migration: string}>  $declaredLegacyTables
     * @return list<TableCollisionData>
     */
    public function planned(array $packages, array $declaredLegacyTables = []): array
    {
        $tables = [];
        $packageRoots = ['core'];
        foreach (['admin', 'marketplace'] as $package) {
            if (in_array('capell-app/' . $package, $packages, true)) {
                $packageRoots[] = $package;
            }
        }

        $root = dirname(__DIR__, 4);
        foreach ($packageRoots as $package) {
            foreach (glob($root . '/' . $package . '/database/migrations/*.php') ?: [] as $path) {
                $source = file_get_contents($path);
                throw_if($source === false, RuntimeException::class, 'Cannot read migration ' . $path);
                preg_match_all("~Schema::create\\(TableName::resolve\\('([^']+)'\\)~", $source, $matches);
                foreach ($matches[1] as $name) {
                    $tables[] = new TableCollisionData($name, TableName::resolve($name), 'capell.database.table_aliases.' . $name, basename($path, '.php'));
                }
            }
        }

        foreach (resolve(MigrationRootRegistry::class)->forPackages($packages) as $root) {
            foreach ($root['tables'] as $migration => $names) {
                foreach ((array) $names as $name) {
                    $tables[] = new TableCollisionData($name, TableName::resolve($name), 'capell.database.table_aliases.' . $name, $migration);
                }
            }
        }

        $external = [
            ['settings', config('settings.repositories.database.table') ?: 'settings', 'settings.repositories.database.table', 'create_settings_table'],
            ['roles', config('permission.table_names.roles', 'roles'), 'permission.table_names.roles', 'create_permission_tables'],
            ['permissions', config('permission.table_names.permissions', 'permissions'), 'permission.table_names.permissions', 'create_permission_tables'],
            ['model_has_roles', config('permission.table_names.model_has_roles', 'model_has_roles'), 'permission.table_names.model_has_roles', 'create_permission_tables'],
            ['model_has_permissions', config('permission.table_names.model_has_permissions', 'model_has_permissions'), 'permission.table_names.model_has_permissions', 'create_permission_tables'],
            ['role_has_permissions', config('permission.table_names.role_has_permissions', 'role_has_permissions'), 'permission.table_names.role_has_permissions', 'create_permission_tables'],
            ['activity_log', config('activitylog.table_name', 'activity_log'), 'activitylog.table_name', 'create_activity_log_table'],
            ['media', config('capell.database.external_tables.media', 'media'), 'capell.database.external_tables.media', 'create_media_table'],
            ['stored_events', config('capell.database.external_tables.stored_events', 'stored_events'), 'capell.database.external_tables.stored_events', '2026_06_26_000001_create_stored_events_table'],
            ['snapshots', config('capell.database.external_tables.snapshots', 'snapshots'), 'capell.database.external_tables.snapshots', '2026_06_26_000002_create_snapshots_table'],
        ];
        foreach ($this->legacyTables($packages, $declaredLegacyTables) as $legacy) {
            $external[] = [$legacy->name, config($legacy->configKey, $legacy->name), $legacy->configKey, $legacy->migration];
        }

        foreach ($external as [$name, $table, $key, $migration]) {
            throw_unless(is_string($table) && $table !== '', RuntimeException::class, 'Invalid configured table name for ' . $name);
            $tables[] = new TableCollisionData($name, $table, $key, $migration);
        }

        return $tables;
    }

    /**
     * The legacy tables the known packages declare for this plan, as plain rows an install input
     * can carry. Call it while the catalogue is cached: the install steps clear that cache.
     *
     * @param  list<string>  $packages
     * @return list<array{name: string, configKey: string, migration: string}>
     */
    public function declaredLegacyTables(array $packages): array
    {
        return array_map(
            static fn (ExtensionLegacyTableData $table): array => $table->toArray(),
            $this->knownLegacyTables($packages),
        );
    }

    /**
     * Tables that packages declare in their manifest `install.legacyTables` because an earlier
     * owner may already hold them, plus declarations captured with the plan for packages that
     * are not registered yet. A package in the plan always contributes its tables. Without an
     * earlier owner from `capell.database.legacy_table_owners` installed the rest stay quiet;
     * once one is, every package that declares such a table is checked, in the plan or not.
     * A declaration whose config key sits outside the files the installer can rewrite is skipped,
     * because a collision on it could be reported but never resolved.
     *
     * @param  list<string>  $packages
     * @param  list<array{name: string, configKey: string, migration: string}>  $declared
     * @return list<ExtensionLegacyTableData>
     */
    private function legacyTables(array $packages, array $declared): array
    {
        $captured = array_filter(array_map(ExtensionLegacyTableData::tryFromArray(...), $declared));

        return array_values(collect([...$this->knownLegacyTables($packages), ...$captured])
            ->filter($this->canSaveConfigKey(...))
            ->unique(static fn (ExtensionLegacyTableData $table): string => $table->name)
            ->all());
    }

    /**
     * @param  list<string>  $packages
     * @return list<ExtensionLegacyTableData>
     */
    private function knownLegacyTables(array $packages): array
    {
        $earlierOwnerInstalled = array_any(
            config()->array('capell.database.legacy_table_owners', []),
            static fn (mixed $owner): bool => is_string($owner) && InstalledVersions::isInstalled($owner),
        );

        return array_values(resolve(PackageRoleResolver::class)->knownPackages()
            ->filter(static fn (PackageData $package): bool => $earlierOwnerInstalled || in_array($package->name, $packages, true))
            ->flatMap(static fn (PackageData $package): array => $package->legacyTables())
            ->filter($this->canSaveConfigKey(...))
            ->unique(static fn (ExtensionLegacyTableData $table): string => $table->name)
            ->all());
    }

    /**
     * Whether the declaration names a table setting the installer can plan and rewrite: a file it
     * supports, and a key that holds a table name rather than a group of settings.
     */
    private function canSaveConfigKey(ExtensionLegacyTableData $table): bool
    {
        $configured = config($table->configKey, $table->name);

        return in_array(explode('.', $table->configKey)[0], WriteTableConfigurationAction::SUPPORTED_ROOTS, true)
            && is_string($configured)
            && $configured !== '';
    }
}

<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Data\Install\TableCollisionData;
use Capell\Core\Support\Migration\MigrationRootRegistry;
use Capell\Core\Support\TableName;
use Illuminate\Support\Facades\Schema;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;
use Spatie\Tags\Tag;

final class FindTableCollisionsAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  list<string>  $packages
     * @return list<TableCollisionData>
     */
    public function handle(array $packages): array
    {
        return array_values(array_filter($this->planned($packages), static function (TableCollisionData $table): bool {
            if (! Schema::hasTable($table->table)) {
                return false;
            }

            return ! TableName::migrationRecorded($table->migration);
        }));
    }

    /**
     * @param  list<string>  $packages
     * @return list<TableCollisionData>
     */
    public function planned(array $packages): array
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
        if (class_exists(Tag::class) || in_array('capell-app/tags', $packages, true)) {
            $external[] = ['tags', config('capell.database.external_tables.tags', 'tags'), 'capell.database.external_tables.tags', 'create_tag_tables'];
            $external[] = ['taggables', config('tags.taggable.table_name', 'taggables'), 'tags.taggable.table_name', 'create_tag_tables'];
        }

        foreach ($external as [$name, $table, $key, $migration]) {
            throw_unless(is_string($table) && $table !== '', RuntimeException::class, 'Invalid configured table name for ' . $name);
            $tables[] = new TableCollisionData($name, $table, $key, $migration);
        }

        return $tables;
    }
}

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Adds site-scoped team support to Spatie Permission tables.
 *
 * Each Site acts as a "team" — assigning a role to a user on a specific site
 * stores team_id = site_id in model_has_roles / model_has_permissions.
 *
 * Super-admins keep team_id = NULL, granting access across all sites.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tableNames = $this->tableNames();

        $columnName = $this->columnName(config('permission.column_names.team_foreign_key'), 'team_id');
        $modelMorphKey = $this->columnName(config('permission.column_names.model_morph_key'), 'model_id');
        $rolePivotKey = $this->columnName(config('permission.column_names.role_pivot_key'), 'role_id');
        $permissionPivotKey = $this->columnName(config('permission.column_names.permission_pivot_key'), 'permission_id');
        $rolesTeamIndex = $this->teamIndexName($tableNames['roles']);
        $modelHasRolesTeamIndex = $this->teamIndexName($tableNames['model_has_roles']);
        $modelHasPermissionsTeamIndex = $this->teamIndexName($tableNames['model_has_permissions']);
        $modelHasRolesTeamUnique = $this->teamUniqueIndexName($tableNames['model_has_roles'], 'role');
        $modelHasPermissionsTeamUnique = $this->teamUniqueIndexName($tableNames['model_has_permissions'], 'permission');

        if (Schema::hasTable($tableNames['roles'])) {
            if (! Schema::hasColumn($tableNames['roles'], $columnName)) {
                Schema::table($tableNames['roles'], function (Blueprint $table) use ($columnName): void {
                    $table->unsignedBigInteger($columnName)->nullable()->after('id');
                });
            }

            if (Schema::hasIndex($tableNames['roles'], ['name', 'guard_name'], 'unique')) {
                Schema::table($tableNames['roles'], static function (Blueprint $table): void {
                    $table->dropUnique(['name', 'guard_name']);
                });
            }

            if (! Schema::hasIndex($tableNames['roles'], $rolesTeamIndex)) {
                Schema::table($tableNames['roles'], function (Blueprint $table) use ($columnName, $rolesTeamIndex): void {
                    $table->index($columnName, $rolesTeamIndex);
                });
            }

            if (! Schema::hasIndex($tableNames['roles'], [$columnName, 'name', 'guard_name'], 'unique')) {
                Schema::table($tableNames['roles'], function (Blueprint $table) use ($columnName): void {
                    $table->unique([$columnName, 'name', 'guard_name']);
                });
            }
        }

        if (Schema::hasTable($tableNames['model_has_roles'])) {
            if (! Schema::hasColumn($tableNames['model_has_roles'], $columnName)) {
                Schema::table($tableNames['model_has_roles'], function (Blueprint $table) use ($columnName): void {
                    $table->unsignedBigInteger($columnName)->nullable()->after('role_id');
                });
            }

            if (! Schema::hasIndex($tableNames['model_has_roles'], $modelHasRolesTeamIndex)) {
                Schema::table($tableNames['model_has_roles'], function (Blueprint $table) use ($columnName, $modelHasRolesTeamIndex): void {
                    $table->index($columnName, $modelHasRolesTeamIndex);
                });
            }

            $this->replaceLegacyPrimaryKeyWithTeamUnique(
                $tableNames['model_has_roles'],
                [$rolePivotKey, $modelMorphKey, 'model_type'],
                [$columnName, $rolePivotKey, $modelMorphKey, 'model_type'],
                $columnName,
                $modelHasRolesTeamUnique,
                $rolePivotKey,
            );
        }

        if (Schema::hasTable($tableNames['model_has_permissions'])) {
            if (! Schema::hasColumn($tableNames['model_has_permissions'], $columnName)) {
                Schema::table($tableNames['model_has_permissions'], function (Blueprint $table) use ($columnName): void {
                    $table->unsignedBigInteger($columnName)->nullable()->after('permission_id');
                });
            }

            if (! Schema::hasIndex($tableNames['model_has_permissions'], $modelHasPermissionsTeamIndex)) {
                Schema::table($tableNames['model_has_permissions'], function (Blueprint $table) use ($columnName, $modelHasPermissionsTeamIndex): void {
                    $table->index($columnName, $modelHasPermissionsTeamIndex);
                });
            }

            $this->replaceLegacyPrimaryKeyWithTeamUnique(
                $tableNames['model_has_permissions'],
                [$permissionPivotKey, $modelMorphKey, 'model_type'],
                [$columnName, $permissionPivotKey, $modelMorphKey, 'model_type'],
                $columnName,
                $modelHasPermissionsTeamUnique,
                $permissionPivotKey,
            );
        }

        resolve(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $tableNames = $this->tableNames();

        $columnName = $this->columnName(config('permission.column_names.team_foreign_key'), 'team_id');
        $modelMorphKey = $this->columnName(config('permission.column_names.model_morph_key'), 'model_id');
        $rolePivotKey = $this->columnName(config('permission.column_names.role_pivot_key'), 'role_id');
        $permissionPivotKey = $this->columnName(config('permission.column_names.permission_pivot_key'), 'permission_id');
        $rolesTeamIndex = $this->teamIndexName($tableNames['roles']);
        $modelHasRolesTeamIndex = $this->teamIndexName($tableNames['model_has_roles']);
        $modelHasPermissionsTeamIndex = $this->teamIndexName($tableNames['model_has_permissions']);
        $modelHasRolesTeamUnique = $this->teamUniqueIndexName($tableNames['model_has_roles'], 'role');
        $modelHasPermissionsTeamUnique = $this->teamUniqueIndexName($tableNames['model_has_permissions'], 'permission');

        foreach ($tableNames as $tableName) {
            if (Schema::hasTable($tableName)
                && Schema::hasColumn($tableName, $columnName)
                && DB::table($tableName)->whereNotNull($columnName)->exists()
            ) {
                throw new RuntimeException(sprintf(
                    'Cannot disable permission teams while [%s] contains team-scoped records.',
                    $tableName,
                ));
            }
        }

        $pivotTables = [
            [
                'table' => $tableNames['roles'],
                'columns' => ['name', 'guard_name'],
            ],
            [
                'table' => $tableNames['model_has_roles'],
                'columns' => [$rolePivotKey, $modelMorphKey, 'model_type'],
            ],
            [
                'table' => $tableNames['model_has_permissions'],
                'columns' => [$permissionPivotKey, $modelMorphKey, 'model_type'],
            ],
        ];

        foreach ($pivotTables as $pivotTable) {
            if (Schema::hasTable($pivotTable['table'])
                && DB::table($pivotTable['table'])
                    ->select($pivotTable['columns'])
                    ->groupBy($pivotTable['columns'])
                    ->havingRaw('COUNT(*) > 1')
                    ->exists()
            ) {
                throw new RuntimeException(sprintf(
                    'Cannot disable permission teams while [%s] contains records that conflict with its legacy unique constraint.',
                    $pivotTable['table'],
                ));
            }
        }

        if (Schema::hasTable($tableNames['roles'])
            && Schema::hasColumn($tableNames['roles'], $columnName)
        ) {
            if (Schema::hasIndex($tableNames['roles'], [$columnName, 'name', 'guard_name'], 'unique')) {
                Schema::table($tableNames['roles'], function (Blueprint $table) use ($columnName): void {
                    $table->dropUnique([$columnName, 'name', 'guard_name']);
                });
            }

            if (Schema::hasIndex($tableNames['roles'], $rolesTeamIndex)) {
                Schema::table($tableNames['roles'], static function (Blueprint $table) use ($rolesTeamIndex): void {
                    $table->dropIndex($rolesTeamIndex);
                });
            }

            Schema::table($tableNames['roles'], function (Blueprint $table) use ($columnName): void {
                $table->dropColumn($columnName);
            });

            if (! Schema::hasIndex($tableNames['roles'], ['name', 'guard_name'], 'unique')) {
                Schema::table($tableNames['roles'], static function (Blueprint $table): void {
                    $table->unique(['name', 'guard_name']);
                });
            }
        }

        if (Schema::hasTable($tableNames['model_has_roles'])
            && Schema::hasColumn($tableNames['model_has_roles'], $columnName)
        ) {
            $this->replaceTeamUniqueWithLegacyPrimaryKey(
                $tableNames['model_has_roles'],
                [$rolePivotKey, $modelMorphKey, 'model_type'],
                [$columnName, $rolePivotKey, $modelMorphKey, 'model_type'],
                $modelHasRolesTeamUnique,
                'model_has_roles_role_model_type_primary',
            );

            $hasTeamIndex = Schema::hasIndex($tableNames['model_has_roles'], $modelHasRolesTeamIndex);

            Schema::table($tableNames['model_has_roles'], function (Blueprint $table) use ($columnName, $hasTeamIndex, $modelHasRolesTeamIndex): void {
                if ($hasTeamIndex) {
                    $table->dropIndex($modelHasRolesTeamIndex);
                }

                $table->dropColumn($columnName);
            });
        }

        if (Schema::hasTable($tableNames['model_has_permissions'])
            && Schema::hasColumn($tableNames['model_has_permissions'], $columnName)
        ) {
            $this->replaceTeamUniqueWithLegacyPrimaryKey(
                $tableNames['model_has_permissions'],
                [$permissionPivotKey, $modelMorphKey, 'model_type'],
                [$columnName, $permissionPivotKey, $modelMorphKey, 'model_type'],
                $modelHasPermissionsTeamUnique,
                'model_has_permissions_permission_model_type_primary',
            );

            $hasTeamIndex = Schema::hasIndex($tableNames['model_has_permissions'], $modelHasPermissionsTeamIndex);

            Schema::table($tableNames['model_has_permissions'], function (Blueprint $table) use ($columnName, $hasTeamIndex, $modelHasPermissionsTeamIndex): void {
                if ($hasTeamIndex) {
                    $table->dropIndex($modelHasPermissionsTeamIndex);
                }

                $table->dropColumn($columnName);
            });
        }
    }

    private function teamIndexName(string $tableName): string
    {
        return str_replace(['-', '.'], '_', strtolower($tableName . '_team_foreign_key_index'));
    }

    private function teamUniqueIndexName(string $tableName, string $pivot): string
    {
        return str_replace(['-', '.'], '_', strtolower($tableName . '_team_' . $pivot . '_model_type_unique'));
    }

    /**
     * @return array{
     *     roles: non-empty-string,
     *     model_has_permissions: non-empty-string,
     *     model_has_roles: non-empty-string
     * }
     */
    private function tableNames(): array
    {
        $configured = config('permission.table_names');

        throw_unless(is_array($configured), RuntimeException::class, 'Permission table names must be configured as an array.');

        $tableNames = [];

        foreach (['roles', 'model_has_permissions', 'model_has_roles'] as $key) {
            $tableName = $configured[$key] ?? null;

            if (! is_string($tableName) || trim($tableName) === '') {
                throw new RuntimeException(sprintf(
                    'Permission table name [%s] must be a non-empty string.',
                    $key,
                ));
            }

            $tableName = trim($tableName);

            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $tableName) !== 1) {
                throw new RuntimeException(sprintf(
                    'Permission table name [%s] must be a safe SQL identifier.',
                    $key,
                ));
            }

            $tableNames[$key] = $tableName;
        }

        /** @var array{roles: non-empty-string, model_has_permissions: non-empty-string, model_has_roles: non-empty-string} $tableNames */
        return $tableNames;
    }

    private function columnName(mixed $configured, string $default): string
    {
        return is_string($configured) && $configured !== '' ? $configured : $default;
    }

    /**
     * @param  list<string>  $legacyColumns
     * @param  list<string>  $teamColumns
     */
    private function replaceLegacyPrimaryKeyWithTeamUnique(
        string $tableName,
        array $legacyColumns,
        array $teamColumns,
        string $teamColumn,
        string $indexName,
        string $pivotColumn,
    ): void {
        // MariaDB 10.5 refuses to drop a PRIMARY KEY while a FOREIGN KEY still
        // relies on it as its only supporting index (errno 150 on the implicit
        // rename at the end of the ALTER TABLE). The legacy primary key here is
        // [pivotColumn, model_id, model_type], so pivotColumn is its leading
        // column and the only thing satisfying the pivot's foreign key. MySQL 8
        // tolerates dropping it directly; MariaDB does not. Drop the foreign
        // key(s) anchored on the pivot column first, then restore them once the
        // new team-scoped unique index is in place. InnoDB auto-creates a
        // supporting index for the restored foreign key if one doesn't already
        // exist, so the final schema keeps the same foreign keys the migration
        // intended.
        $pivotForeignKeys = $this->dropForeignKeysForColumn($tableName, $pivotColumn);

        if (Schema::hasIndex($tableName, $teamColumns, 'primary')) {
            Schema::table($tableName, static function (Blueprint $table): void {
                $table->dropPrimary();
            });
        }

        if (! $this->columnIsNullable($tableName, $teamColumn)) {
            Schema::table($tableName, static function (Blueprint $table) use ($teamColumn): void {
                $table->unsignedBigInteger($teamColumn)->nullable()->change();
            });
        }

        if (Schema::hasIndex($tableName, $legacyColumns, 'primary')) {
            Schema::table($tableName, static function (Blueprint $table): void {
                $table->dropPrimary();
            });
        }

        if (! Schema::hasIndex($tableName, $teamColumns, 'unique')) {
            Schema::table($tableName, static function (Blueprint $table) use ($indexName, $teamColumns): void {
                $table->unique($teamColumns, $indexName);
            });
        }

        $this->restoreForeignKeys($tableName, $pivotForeignKeys);
    }

    private function columnIsNullable(string $tableName, string $columnName): bool
    {
        foreach (Schema::getColumns($tableName) as $column) {
            if (($column['name'] ?? null) === $columnName) {
                return ($column['nullable'] ?? false) === true;
            }
        }

        return false;
    }

    /**
     * Drop every foreign key on $tableName whose referencing columns include
     * $pivotColumn, and return their definitions so they can be restored later.
     *
     * @return list<array{name: string, columns: list<string>, foreign_schema: string|null, foreign_table: string, foreign_columns: list<string>, on_update: string, on_delete: string}>
     */
    private function dropForeignKeysForColumn(string $tableName, string $pivotColumn): array
    {
        $pivotForeignKeys = [];

        foreach (Schema::getForeignKeys($tableName) as $foreignKeyDefinition) {
            if (! in_array($pivotColumn, $foreignKeyDefinition['columns'], true)) {
                continue;
            }

            $pivotForeignKeys[] = $foreignKeyDefinition;

            // Pass column names, not the constraint name, to dropForeign(): SQLite's
            // grammar only supports the recreate-table path (which needs the column
            // list) and throws "does not support dropping foreign keys by name"
            // otherwise. MySQL/MariaDB accept either form and resolve back to the
            // same conventionally-named constraint, so this stays cross-database safe.
            Schema::table($tableName, static function (Blueprint $table) use ($foreignKeyDefinition): void {
                $table->dropForeign($foreignKeyDefinition['columns']);
            });
        }

        return $pivotForeignKeys;
    }

    /**
     * @param  list<array{name: string, columns: list<string>, foreign_schema: string|null, foreign_table: string, foreign_columns: list<string>, on_update: string, on_delete: string}>  $foreignKeyDefinitions
     */
    private function restoreForeignKeys(string $tableName, array $foreignKeyDefinitions): void
    {
        $existingForeignKeyNames = array_column(Schema::getForeignKeys($tableName), 'name');

        foreach ($foreignKeyDefinitions as $foreignKeyDefinition) {
            if (in_array($foreignKeyDefinition['name'], $existingForeignKeyNames, true)) {
                continue;
            }

            Schema::table($tableName, static function (Blueprint $table) use ($foreignKeyDefinition): void {
                $table->foreign($foreignKeyDefinition['columns'], $foreignKeyDefinition['name'])
                    ->references($foreignKeyDefinition['foreign_columns'])
                    ->on($foreignKeyDefinition['foreign_table'])
                    ->onDelete($foreignKeyDefinition['on_delete'])
                    ->onUpdate($foreignKeyDefinition['on_update']);
            });
        }
    }

    /**
     * @param  list<string>  $legacyColumns
     * @param  list<string>  $teamColumns
     */
    private function replaceTeamUniqueWithLegacyPrimaryKey(
        string $tableName,
        array $legacyColumns,
        array $teamColumns,
        string $teamIndexName,
        string $primaryIndexName,
    ): void {
        if (Schema::hasIndex($tableName, $teamColumns, 'unique')) {
            Schema::table($tableName, static function (Blueprint $table) use ($teamIndexName): void {
                $table->dropUnique($teamIndexName);
            });
        }

        if (Schema::hasIndex($tableName, $teamColumns, 'primary')) {
            Schema::table($tableName, static function (Blueprint $table): void {
                $table->dropPrimary();
            });
        }

        if (! Schema::hasIndex($tableName, $legacyColumns, 'primary')) {
            Schema::table($tableName, static function (Blueprint $table) use ($legacyColumns, $primaryIndexName): void {
                $table->primary($legacyColumns, $primaryIndexName);
            });
        }
    }
};

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
        $tableNames = config('permission.table_names', [
            'roles' => 'roles',
            'model_has_permissions' => 'model_has_permissions',
            'model_has_roles' => 'model_has_roles',
        ]);

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
            );
        }

        resolve(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $tableNames = config('permission.table_names', [
            'roles' => 'roles',
            'model_has_permissions' => 'model_has_permissions',
            'model_has_roles' => 'model_has_roles',
        ]);

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
    ): void {
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

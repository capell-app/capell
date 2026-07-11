<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
            'model_has_permissions' => 'model_has_permissions',
            'model_has_roles' => 'model_has_roles',
        ]);

        $columnName = config('permission.column_names.team_foreign_key', 'team_id');

        if (Schema::hasTable($tableNames['model_has_roles'])
            && ! Schema::hasColumn($tableNames['model_has_roles'], $columnName)
        ) {
            Schema::table($tableNames['model_has_roles'], function (Blueprint $table) use ($columnName): void {
                $table->unsignedBigInteger($columnName)->nullable()->after('role_id');
                $table->index($columnName, 'model_has_roles_team_foreign_key_index');
            });
        }

        if (Schema::hasTable($tableNames['model_has_permissions'])
            && ! Schema::hasColumn($tableNames['model_has_permissions'], $columnName)
        ) {
            Schema::table($tableNames['model_has_permissions'], function (Blueprint $table) use ($columnName): void {
                $table->unsignedBigInteger($columnName)->nullable()->after('permission_id');
                $table->index($columnName, 'model_has_permissions_team_foreign_key_index');
            });
        }

        resolve(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $tableNames = config('permission.table_names', [
            'model_has_permissions' => 'model_has_permissions',
            'model_has_roles' => 'model_has_roles',
        ]);

        $columnName = config('permission.column_names.team_foreign_key', 'team_id');

        if (Schema::hasTable($tableNames['model_has_roles'])
            && Schema::hasColumn($tableNames['model_has_roles'], $columnName)
        ) {
            Schema::table($tableNames['model_has_roles'], function (Blueprint $table) use ($columnName): void {
                $table->dropIndex('model_has_roles_team_foreign_key_index');
                $table->dropColumn($columnName);
            });
        }

        if (Schema::hasTable($tableNames['model_has_permissions'])
            && Schema::hasColumn($tableNames['model_has_permissions'], $columnName)
        ) {
            Schema::table($tableNames['model_has_permissions'], function (Blueprint $table) use ($columnName): void {
                $table->dropIndex('model_has_permissions_team_foreign_key_index');
                $table->dropColumn($columnName);
            });
        }
    }
};

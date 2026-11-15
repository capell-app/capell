<?php

declare(strict_types=1);

use Capell\Admin\Enums\CapellPermission;
use Capell\Admin\Enums\ResourceEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Publishing a page used to need only the page update permission. It now has its
 * own permission; every role that could update pages when this runs is given it,
 * so no existing role loses the ability to publish. It runs once, so a role an
 * administrator later takes it from stays without it.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Permission names come from the Filament Shield configuration; without
        // Shield there are no admin roles to carry over.
        if (! app()->bound('filament-shield')) {
            return;
        }

        $tables = (array) config('permission.table_names', []);

        if (! Schema::hasTable((string) ($tables['permissions'] ?? 'permissions')) || ! Schema::hasTable((string) ($tables['role_has_permissions'] ?? 'role_has_permissions'))) {
            return;
        }

        $updateName = ResourceEnum::Page->permission('update');
        $publishName = CapellPermission::PublishPage->name();

        foreach (Permission::query()->where('name', $updateName)->get() as $update) {
            $publish = Permission::findOrCreate($publishName, $update->guard_name);

            foreach ($update->roles()->get() as $role) {
                if ($role instanceof Role && ! $role->hasPermissionTo($publish)) {
                    $role->givePermissionTo($publish);
                }
            }
        }

        resolve(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Removing the grants would take publishing away from roles that had it.
    }
};

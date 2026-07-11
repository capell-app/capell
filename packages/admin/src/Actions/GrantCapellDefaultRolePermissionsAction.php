<?php

declare(strict_types=1);

namespace Capell\Admin\Actions;

use Capell\Admin\Enums\CapellPermission;
use Capell\Admin\Enums\PermissionSyncMode;
use Lorisleiva\Actions\Concerns\AsObject;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class GrantCapellDefaultRolePermissionsAction
{
    use AsObject;

    public function handle(PermissionSyncMode $mode): void
    {
        $guard = config('auth.defaults.guard', 'web');

        foreach ($this->rolePermissionMap($mode) as $roleName => $permissionNames) {
            $role = Role::findOrCreate($roleName);

            foreach ($permissionNames as $permissionName) {
                if (! $role->hasPermissionTo($permissionName, $guard)) {
                    $role->givePermissionTo($permissionName);
                }
            }
        }

        resolve(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function rolePermissionMap(PermissionSyncMode $mode): array
    {
        $rolePermissionMap = [
            'editor' => [],
            'admin' => [],
            'super_admin' => [],
        ];

        foreach (CapellPermission::cases() as $permission) {
            $roleNames = $mode === PermissionSyncMode::Install
                ? $permission->installRoles()
                : $permission->upgradeRoles();

            foreach ($roleNames as $roleName) {
                $rolePermissionMap[$roleName][] = $permission->name();
            }
        }

        return array_map(
            fn (array $permissionNames): array => array_values(array_unique($permissionNames)),
            $rolePermissionMap,
        );
    }
}

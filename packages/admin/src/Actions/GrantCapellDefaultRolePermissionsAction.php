<?php

declare(strict_types=1);

namespace Capell\Admin\Actions;

use Capell\Admin\Enums\CapellPermission;
use Capell\Admin\Enums\PermissionSyncMode;
use Capell\Admin\Enums\ResourceEnum;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class GrantCapellDefaultRolePermissionsAction
{
    use AsFake;
    use AsObject;

    public function handle(PermissionSyncMode $mode): void
    {
        $guard = config('auth.defaults.guard', 'web');

        AssignPermissionsToRole::run(resources: [ResourceEnum::PageUrl]);

        foreach ($this->rolePermissionMap($mode, $guard) as $roleName => $permissionNames) {
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
    private function rolePermissionMap(PermissionSyncMode $mode, string $guardName): array
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

        $pageUrlPermissions = [
            ResourceEnum::PageUrl->permission('view_any'),
            ResourceEnum::PageUrl->permission('view'),
            ResourceEnum::PageUrl->permission('create'),
            ResourceEnum::PageUrl->permission('update'),
        ];

        if ($mode === PermissionSyncMode::Upgrade) {
            $existingPageUrlPermissions = Permission::query()
                ->where('guard_name', $guardName)
                ->whereIn('name', $pageUrlPermissions)
                ->pluck('name')
                ->all();

            $rolePermissionMap['editor'] = [
                ...$rolePermissionMap['editor'],
                ...$existingPageUrlPermissions,
            ];
        } else {
            $rolePermissionMap['editor'] = [
                ...$rolePermissionMap['editor'],
                ...$pageUrlPermissions,
            ];
        }

        return array_map(
            fn (array $permissionNames): array => array_values(array_unique($permissionNames)),
            $rolePermissionMap,
        );
    }
}

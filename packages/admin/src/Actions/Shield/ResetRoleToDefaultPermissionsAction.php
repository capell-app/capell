<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Shield;

use Capell\Admin\Data\Shield\RolePermissionChangeSetData;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class ResetRoleToDefaultPermissionsAction
{
    use AsFake;
    use AsObject;

    public function handle(Role $role, ?Model $actor = null): void
    {
        $guardName = $role->guard_name;
        $before = $this->permissionNamesForRole($role);
        $permissionNames = ResolveDefaultRolePermissionsAction::run($role->name, $guardName);

        $role->syncPermissions(
            Permission::query()
                ->where('guard_name', $guardName)
                ->whereIn('name', $permissionNames)
                ->get(),
        );

        resolve(PermissionRegistrar::class)->forgetCachedPermissions();

        $role->refresh()->load('permissions');

        $after = $this->permissionNamesForRole($role);

        LogRolePermissionChangesAction::run(
            $role,
            new RolePermissionChangeSetData(
                before: $before,
                after: $after,
                added: array_values(array_diff($after, $before)),
                removed: array_values(array_diff($before, $after)),
                unchanged: array_values(array_intersect($before, $after)),
            ),
            $actor,
        );
    }

    /**
     * @return list<string>
     */
    private function permissionNamesForRole(Role $role): array
    {
        return array_values($role->permissions()
            ->where('guard_name', $role->guard_name)
            ->pluck('name')
            ->sort()
            ->values()
            ->all());
    }
}

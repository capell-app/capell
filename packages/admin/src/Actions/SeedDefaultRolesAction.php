<?php

declare(strict_types=1);

namespace Capell\Admin\Actions;

use BezhanSalleh\FilamentShield\Support\Utils;
use Capell\Admin\Actions\Shield\ResolveDefaultRolePermissionsAction;
use Illuminate\Database\Eloquent\Collection;
use Lorisleiva\Actions\Concerns\AsObject;
use Spatie\Permission\Contracts\Role as RoleContract;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Creates the three built-in Capell roles and assigns their permissions.
 *
 * Roles:
 *  - editor      : create/edit content
 *  - admin       : full content management
 *  - super_admin  : all permissions for the role guard
 *
 * Safe to run multiple times (uses findOrCreate + syncPermissions).
 * Must be called after AssignPermissionsToRole so permissions exist.
 */
class SeedDefaultRolesAction
{
    use AsObject;

    public function handle(): void
    {
        $this->seedPanelUser();
        $this->seedEditor();
        $this->seedAdmin();
        $this->seedSuperAdmin();
    }

    private function seedPanelUser(): void
    {
        if (! Utils::isPanelUserRoleEnabled()) {
            return;
        }

        Role::findOrCreate(
            Utils::getPanelUserRoleName(),
            (string) config('auth.defaults.guard', 'web'),
        );
    }

    private function seedEditor(): void
    {
        $role = Role::findOrCreate('editor');

        $role->syncPermissions($this->resolveDefaultPermissions($role));
    }

    private function seedAdmin(): void
    {
        $role = Role::findOrCreate('admin');

        $role->syncPermissions($this->resolveDefaultPermissions($role));
    }

    private function seedSuperAdmin(): void
    {
        $role = Role::findOrCreate('super_admin');

        $role->syncPermissions($this->resolveDefaultPermissions($role));
    }

    /**
     * @param  array<string>  $names
     * @return Collection<int, Permission>
     */
    private function resolve(array $names, string $guardName): Collection
    {
        return Permission::query()
            ->where('guard_name', $guardName)
            ->whereIn('name', $names)
            ->get();
    }

    /**
     * @return Collection<int, Permission>
     */
    private function resolveDefaultPermissions(RoleContract $role): Collection
    {
        $guardName = $role->guard_name ?? (string) config('auth.defaults.guard', 'web');

        return $this->resolve(
            ResolveDefaultRolePermissionsAction::run(
                $role->name,
                $guardName,
            ),
            $guardName,
        );
    }
}

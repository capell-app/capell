<?php

declare(strict_types=1);

namespace Capell\Admin\Actions;

use BezhanSalleh\FilamentShield\Support\Utils;
use Capell\Core\Data\Permissions\PermissionData;
use Capell\Core\Data\Permissions\PermissionSetData;
use Capell\Core\Support\TableName;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Spatie\Permission\Contracts\Role;

/**
 * Grants each declared package permission to its default roles, once.
 *
 * A grant is recorded per permission and guard, so a later sync never hands
 * back a permission an administrator removed, and a key the manifest listed
 * before it was typed still receives its defaults the first time. Only roles
 * that already exist are granted: a package can name a role, not create one.
 * A key already owned by another package is left to that package.
 *
 * @method static void run(array<string, PermissionSetData> $declared, ?string $guardName = null)
 */
class GrantDeclaredPermissionRolesAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  array<string, PermissionSetData>  $declared  package name => permissions, in package order
     */
    public function handle(array $declared, ?string $guardName = null): void
    {
        $guard = $guardName ?? Utils::getFilamentAuthGuard();
        $table = TableName::resolve('admin_permission_default_grants');
        $owners = DB::table($table)->where('guard_name', $guard)->pluck('package', 'permission')->all();
        $roles = [];

        foreach ($declared as $package => $set) {
            foreach ($set->permissions as $permission) {
                $owner = $owners[$permission->key] ?? null;

                if ($owner !== null) {
                    if ($owner !== $package) {
                        Log::warning('Capell permission declared by two packages; the first owner keeps it.', [
                            'permission' => $permission->key,
                            'owner' => $owner,
                            'package' => $package,
                        ]);
                    }

                    continue;
                }

                $this->grant($permission, $guard, $roles);
                DB::table($table)->insert([
                    'package' => $package,
                    'permission' => $permission->key,
                    'guard_name' => $guard,
                    'granted_at' => now(),
                ]);
                $owners[$permission->key] = $package;
            }
        }
    }

    /** @param array<string, Role|null> $roles */
    private function grant(PermissionData $permission, string $guard, array &$roles): void
    {
        $roleModel = Utils::getRoleModel();

        foreach ($permission->defaultRoles as $roleName) {
            if (! array_key_exists($roleName, $roles)) {
                $role = $roleModel::query()->where('name', $roleName)->where('guard_name', $guard)->first();
                $roles[$roleName] = $role instanceof Role ? $role : null;
            }

            $role = $roles[$roleName];

            if ($role instanceof Role && ! $role->hasPermissionTo($permission->key, $guard)) {
                $role->givePermissionTo($permission->key);
            }
        }
    }
}

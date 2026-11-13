<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Users;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Spatie\Permission\Models\Role;

/**
 * Nobody can hand out more than they hold: granting a role requires holding
 * every permission the role carries at the moment of granting. A role later
 * widened on the Roles screen is therefore re-checked on every grant.
 */
class RoleGrantGuard
{
    public function canGrant(Authenticatable $actor, Role $role): bool
    {
        if (method_exists($actor, 'isGlobalAdmin') && $actor->isGlobalAdmin()) {
            return true;
        }

        if (! method_exists($actor, 'checkPermissionTo')) {
            return false;
        }

        foreach ($role->permissions()->pluck('name') as $permissionName) {
            if (! $actor->checkPermissionTo((string) $permissionName, $role->guard_name)) {
                return false;
            }
        }

        return true;
    }
}

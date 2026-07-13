<?php

declare(strict_types=1);

namespace Capell\Core\Models\Concerns;

use Capell\Core\Models\Site;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Adds site-scoped permission helpers to the User model.
 *
 * Spatie teams are used under the hood — each Site's primary key acts as the
 * team_id in model_has_roles / model_has_permissions. Setting the registrar's
 * team ID (via SetSitePermissionScope middleware) causes all hasPermissionTo()
 * and hasRole() calls to be automatically scoped to the active site.
 *
 * Super-admins should carry their roles with team_id = NULL, which bypasses
 * scoping and is treated as "access all sites".
 */
trait HasSitePermissions
{
    /**
     * Assign a role to this user scoped to the given site.
     * Safe to call multiple times — silently skips duplicate assignments.
     */
    public function assignRoleForSite(Site $site, string|Role $role): void
    {
        $registrar = resolve(PermissionRegistrar::class);
        $previous = $registrar->getPermissionsTeamId();

        try {
            $registrar->setPermissionsTeamId($site->getKey());
            $this->assignRole($role);
        } finally {
            $registrar->setPermissionsTeamId($previous);
        }
    }

    /**
     * Remove a role from this user for the given site.
     */
    public function removeRoleForSite(Site $site, string|Role $role): void
    {
        $registrar = resolve(PermissionRegistrar::class);
        $previous = $registrar->getPermissionsTeamId();

        try {
            $registrar->setPermissionsTeamId($site->getKey());
            $this->removeRole($role);
        } finally {
            $registrar->setPermissionsTeamId($previous);
        }
    }

    /**
     * Returns the roles this user holds specifically on the given site.
     *
     * @return Collection<int, Role>
     */
    public function getRolesForSite(Site $site): Collection
    {
        $tableNames = config('permission.table_names', []);
        $modelHasRolesTable = is_array($tableNames) && is_string($tableNames['model_has_roles'] ?? null)
            ? $tableNames['model_has_roles']
            : 'model_has_roles';
        $teamColumnConfig = config('permission.column_names.team_foreign_key', 'team_id');
        $teamColumn = is_string($teamColumnConfig) && $teamColumnConfig !== '' ? $teamColumnConfig : 'team_id';

        return Role::query()
            ->join($modelHasRolesTable, $modelHasRolesTable . '.role_id', '=', 'roles.id')
            ->where($modelHasRolesTable . '.model_type', $this->getMorphClass())
            ->where($modelHasRolesTable . '.model_id', $this->getKey())
            ->where($modelHasRolesTable . '.' . $teamColumn, $site->getKey())
            ->select('roles.*')
            ->get();
    }

    /**
     * Returns true when the user has the given role on the site (or globally
     * via a null-team role, e.g. super-admin).
     */
    public function hasRoleForSite(Site $site, string|Role $role): bool
    {
        if ($this->hasGlobalRole($role)) {
            // Global (team_id = null) role — treat as site-agnostic super-admin
            return true;
        }

        return $this->getRolesForSite($site)->contains('name', is_string($role) ? $role : $role->name);
    }

    /**
     * Returns true when the user has the given permission in the context of
     * the active site (team). The SetSitePermissionScope middleware must have
     * already called setPermissionsTeamId() for this to work correctly.
     */
    public function hasPermissionForSite(Site $site, string $permission): bool
    {
        $registrar = resolve(PermissionRegistrar::class);
        $previous = $registrar->getPermissionsTeamId();

        try {
            $registrar->setPermissionsTeamId($site->getKey());
            $registrar->forgetCachedPermissions();

            return $this->hasPermissionTo($permission);
        } finally {
            $registrar->setPermissionsTeamId($previous);
            $registrar->forgetCachedPermissions();
        }
    }

    /**
     * Returns all site IDs where this user holds at least one role.
     *
     * @return Collection<int, int>
     */
    public function getAssignedSiteIds(): Collection
    {
        return $this->roles()
            ->whereNotNull('model_has_roles.team_id')
            ->pluck('model_has_roles.team_id')
            ->unique()
            ->values();
    }

    /**
     * Returns true when this user has no site-scoped roles and no global roles.
     * Useful for guarding super-admin-only sections.
     */
    public function isGlobalAdmin(): bool
    {
        $configured = config('capell.roles.super_admin', config('filament-shield.super_admin.name', 'super_admin'));
        $superAdminRole = is_string($configured) && $configured !== '' ? $configured : 'super_admin';

        return $this->hasGlobalRole($superAdminRole);
    }

    private function hasGlobalRole(string|Role $role): bool
    {
        $tableNames = config('permission.table_names', []);
        $modelHasRolesTable = is_array($tableNames) && is_string($tableNames['model_has_roles'] ?? null)
            ? $tableNames['model_has_roles']
            : 'model_has_roles';
        $teamColumnConfig = config('permission.column_names.team_foreign_key', 'team_id');
        $teamColumn = is_string($teamColumnConfig) && $teamColumnConfig !== '' ? $teamColumnConfig : 'team_id';

        $query = DB::table($modelHasRolesTable)
            ->where('model_type', $this->getMorphClass())
            ->where('model_id', $this->getKey())
            ->whereNull($teamColumn);

        if ($role instanceof Role) {
            return $query->where('role_id', $role->getKey())->exists();
        }

        return $query->whereIn(
            'role_id',
            Role::query()
                ->where('name', $role)
                ->where('guard_name', 'web')
                ->select('id'),
        )
            ->exists();
    }
}

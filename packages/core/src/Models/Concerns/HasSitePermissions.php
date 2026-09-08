<?php

declare(strict_types=1);

namespace Capell\Core\Models\Concerns;

use Capell\Core\Models\Site;
use Capell\Core\Support\Permissions\PermissionTeamContext;
use Illuminate\Database\Eloquent\Builder;
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
        PermissionTeamContext::run($site->getKey(), function () use ($role): void {
            $this->assignRole($role);
        }, $this);
    }

    /** Assign account-wide roles without inheriting a request's site context. */
    public function assignGlobalRole(string $role, string $guard = 'web'): void
    {
        PermissionTeamContext::run(null, function () use ($role, $guard): void {
            $this->assignRole(Role::findOrCreate($role, $guard));
        }, $this);
    }

    /**
     * Remove a role from this user for the given site.
     */
    public function removeRoleForSite(Site $site, string|Role $role): void
    {
        PermissionTeamContext::run($site->getKey(), function () use ($role): void {
            $this->removeRole($role);
        }, $this);
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
            ->where(fn (Builder $query): Builder => $query->whereNull('roles.' . $teamColumn)->orWhere('roles.' . $teamColumn, $site->getKey()))
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
        return PermissionTeamContext::run(
            $site->getKey(),
            fn (): bool => $this->checkPermissionTo($permission),
            $this,
        );
    }

    /**
     * Authorization scope for the active team. Membership selectors must use
     * getAllAssignedSiteIds() so grants cannot bleed between assigned sites.
     *
     * @return Collection<int, int>
     */
    public function getAssignedSiteIds(): Collection
    {
        $siteIds = $this->getAllAssignedSiteIds();
        $registrar = resolve(PermissionRegistrar::class);

        return $registrar->teams
            ? $siteIds->filter(fn (int $id): bool => (string) $id === (string) $registrar->getPermissionsTeamId())->values()
            : $siteIds;
    }

    /**
     * Returns all site IDs where this user holds at least one role.
     *
     * @return Collection<int, int>
     */
    public function getAllAssignedSiteIds(): Collection
    {
        // Membership discovery must see every assignment, even while another
        // team is active. Spatie's roles() relationship filters to that team.
        $pivot = (string) config('permission.table_names.model_has_roles', 'model_has_roles');
        $roles = (string) config('permission.table_names.roles', 'roles');
        $team = (string) config('permission.column_names.team_foreign_key', 'team_id');

        return DB::table($pivot)
            ->join($roles, $roles . '.id', '=', $pivot . '.role_id')
            ->where($pivot . '.model_type', $this->getMorphClass())
            ->where($pivot . '.model_id', $this->getKey())
            ->whereNotNull($pivot . '.' . $team)
            ->where(fn ($query) => $query->whereNull($roles . '.' . $team)
                ->orWhereColumn($roles . '.' . $team, $pivot . '.' . $team))
            ->pluck($pivot . '.' . $team)
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();
    }

    /**
     * Returns true for an explicit null-team super-admin assignment.
     */
    public function isGlobalAdmin(): bool
    {
        $configured = config('capell.roles.super_admin', config('filament-shield.super_admin.name', 'super_admin'));
        $superAdminRole = is_string($configured) && $configured !== '' ? $configured : 'super_admin';

        return $this->hasGlobalRole($superAdminRole);
    }

    /** Global account capabilities must be checked independently of the selected site. */
    public function hasGlobalRole(string|Role $role): bool
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
            return $role->getAttribute($teamColumn) === null
                && $query->where('role_id', $role->getKey())->exists();
        }

        return $query->whereIn(
            'role_id',
            Role::query()
                ->where('name', $role)
                ->where('guard_name', 'web')
                ->whereNull($teamColumn)
                ->select('id'),
        )
            ->exists();
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function scopeGlobalAdmins(Builder $query): Builder
    {
        $configured = config('capell.roles.super_admin', config('filament-shield.super_admin.name', 'super_admin'));
        $superAdminRole = is_string($configured) && $configured !== '' ? $configured : 'super_admin';

        return $this->scopeGlobalRole($query, $superAdminRole);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function scopeGlobalRole(Builder $query, string $role): Builder
    {
        $tableNames = config('permission.table_names', []);
        $modelHasRolesTable = is_array($tableNames) && is_string($tableNames['model_has_roles'] ?? null)
            ? $tableNames['model_has_roles']
            : 'model_has_roles';
        $teamColumnConfig = config('permission.column_names.team_foreign_key', 'team_id');
        $teamColumn = is_string($teamColumnConfig) && $teamColumnConfig !== '' ? $teamColumnConfig : 'team_id';

        return $query->whereIn($this->qualifyColumn($this->getKeyName()), DB::table($modelHasRolesTable)
            ->where('model_type', $this->getMorphClass())
            ->whereNull($teamColumn)
            ->whereIn('role_id', Role::query()->where('name', $role)->where('guard_name', 'web')->whereNull($teamColumn)->select('id'))
            ->select('model_id'));
    }
}

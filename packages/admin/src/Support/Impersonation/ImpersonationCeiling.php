<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Impersonation;

use Capell\Core\Support\Permissions\SiteAccess;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use WeakMap;

/**
 * Decides whether an actor may assume a target's session without gaining any
 * authority the actor does not already hold.
 *
 * An impersonated session carries every right the target holds in every site
 * team, so the target must sit entirely inside the actor's ceiling: each site
 * the target belongs to must be one the actor can access, and in each of those
 * contexts (and account-wide) the target may hold no permission the actor lacks.
 */
final class ImpersonationCeiling
{
    /** @var WeakMap<Model, array<string, array<string, true>>>|null */
    private static ?WeakMap $actorGrants = null;

    public static function permits(Authenticatable $actor, Authenticatable $target): bool
    {
        if (! $actor instanceof Model || ! $target instanceof Model) {
            return false;
        }

        $access = SiteAccess::forActor($actor, true);

        if ($access->isGlobal()) {
            return true;
        }

        $targetGrants = self::grants($target);

        foreach (self::targetSiteIds($target, $targetGrants) as $siteId) {
            if (! $access->canSiteId($siteId)) {
                return false;
            }
        }

        // A role named like the super admin bypasses permission checks, whatever
        // team it is held in, so it can never sit under a non-global ceiling.
        if (self::holdsSuperAdminRole($target)) {
            return false;
        }

        $actorGrants = self::actorGrants($actor);
        $global = $targetGrants[''] ?? [];

        foreach (array_keys($targetGrants + ['' => []]) as $team) {
            $held = ($actorGrants[$team] ?? []) + ($actorGrants[''] ?? []);

            if (array_diff_key(($targetGrants[$team] ?? []) + $global, $held) !== []) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, array<string, true>>  $grants
     * @return list<int>
     */
    private static function targetSiteIds(Model $target, array $grants): array
    {
        $ids = [];

        if (method_exists($target, 'getAllAssignedSiteIds')) {
            foreach ($target->getAllAssignedSiteIds() as $id) {
                $ids[(int) $id] = true;
            }
        }

        foreach (array_keys($grants) as $team) {
            if ($team !== '') {
                $ids[(int) $team] = true;
            }
        }

        return array_keys($ids);
    }

    /** @return array<string, array<string, true>> */
    private static function actorGrants(Model $actor): array
    {
        self::$actorGrants ??= new WeakMap;

        // An actor's rights cannot change mid-request, and the Users table asks
        // once per row; cache per actor instance so rows add no actor queries.
        return self::$actorGrants[$actor] ??= self::grants($actor);
    }

    /**
     * Permission names held by the user, keyed by team ('' for account-wide),
     * from direct grants and from every role assignment.
     *
     * @return array<string, array<string, true>>
     */
    private static function grants(Model $user): array
    {
        $tables = (array) config('permission.table_names', []);
        $permissions = (string) ($tables['permissions'] ?? 'permissions');
        $roles = (string) ($tables['roles'] ?? 'roles');
        $modelPermissions = (string) ($tables['model_has_permissions'] ?? 'model_has_permissions');
        $modelRoles = (string) ($tables['model_has_roles'] ?? 'model_has_roles');
        $rolePermissions = (string) ($tables['role_has_permissions'] ?? 'role_has_permissions');
        $teams = resolve(PermissionRegistrar::class)->teams;
        $teamColumn = (string) config('permission.column_names.team_foreign_key', 'team_id');
        $result = [];

        $direct = DB::table($modelPermissions)
            ->join($permissions, $permissions . '.id', '=', $modelPermissions . '.permission_id')
            ->where($modelPermissions . '.model_type', $user->getMorphClass())
            ->where($modelPermissions . '.model_id', $user->getKey())
            ->select($permissions . '.name as name')
            ->when($teams, fn ($query) => $query->addSelect($modelPermissions . '.' . $teamColumn . ' as team'))
            ->get();

        $viaRoles = DB::table($modelRoles)
            ->join($roles, $roles . '.id', '=', $modelRoles . '.role_id')
            ->join($rolePermissions, $rolePermissions . '.role_id', '=', $roles . '.id')
            ->join($permissions, $permissions . '.id', '=', $rolePermissions . '.permission_id')
            ->where($modelRoles . '.model_type', $user->getMorphClass())
            ->where($modelRoles . '.model_id', $user->getKey())
            ->select($permissions . '.name as name')
            ->when($teams, fn ($query) => $query
                ->addSelect($modelRoles . '.' . $teamColumn . ' as team')
                ->where(fn ($roleTeam) => $roleTeam->whereNull($roles . '.' . $teamColumn)
                    ->orWhereColumn($roles . '.' . $teamColumn, $modelRoles . '.' . $teamColumn)))
            ->get();

        foreach ($direct->concat($viaRoles) as $row) {
            $team = $teams && $row->team !== null ? (string) $row->team : '';
            $result[$team][(string) $row->name] = true;
        }

        return $result;
    }

    private static function holdsSuperAdminRole(Model $user): bool
    {
        $configured = config('capell.roles.super_admin', config('filament-shield.super_admin.name', 'super_admin'));
        $name = is_string($configured) && $configured !== '' ? $configured : 'super_admin';
        $tables = (array) config('permission.table_names', []);
        $roles = (string) ($tables['roles'] ?? 'roles');
        $modelRoles = (string) ($tables['model_has_roles'] ?? 'model_has_roles');

        return DB::table($modelRoles)
            ->join($roles, $roles . '.id', '=', $modelRoles . '.role_id')
            ->where($modelRoles . '.model_type', $user->getMorphClass())
            ->where($modelRoles . '.model_id', $user->getKey())
            ->where($roles . '.name', $name)
            ->exists();
    }
}

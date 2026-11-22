<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Impersonation;

use Capell\Core\Support\Permissions\PermissionTeamContext;
use Capell\Core\Support\Permissions\SiteAccess;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\PermissionRegistrar;

/**
 * Decides whether an actor may assume a target's session without gaining any
 * authority the actor does not already hold.
 *
 * An impersonated session carries every right the target holds in every site
 * team, so the target must sit entirely inside the actor's ceiling: each site
 * the target belongs to must be one the actor can access, and in each of those
 * contexts (and account-wide) the target may hold no permission the actor lacks.
 *
 * Bound per request: the Users table asks once per row, and the actor's own
 * permissions are read once for all of them.
 */
final class ImpersonationCeiling
{
    /** @var array<string, array<int|string, array<string, true>>> */
    private array $actorPermissions = [];

    public function permits(Authenticatable $actor, Authenticatable $target): bool
    {
        if (! $actor instanceof Model || ! $target instanceof Model) {
            return false;
        }

        $access = SiteAccess::forActor($actor, true);

        // Global here means the super admin role and nothing less: that role
        // passes every permission check, so no target can exceed it. Any other
        // account-wide role is not global and is compared below.
        if ($access->isGlobal()) {
            return true;
        }

        if (! method_exists($target, 'getAllAssignedSiteIds')) {
            return false;
        }

        $siteIds = array_map(intval(...), $target->getAllAssignedSiteIds()->all());

        foreach ($siteIds as $siteId) {
            if (! $access->canSiteId($siteId)) {
                return false;
            }
        }

        // Without site teams every permission lives in the one account-wide context.
        if (! resolve(PermissionRegistrar::class)->teams) {
            $siteIds = [];
        }

        $targetGlobal = $this->permissionNames($target, null);

        if ($targetGlobal === null) {
            return false;
        }

        $actorGlobal = $this->actorPermissionNames($actor, null);

        if (array_diff_key($targetGlobal, $actorGlobal) !== []) {
            return false;
        }

        foreach ($siteIds as $siteId) {
            $held = $this->permissionNames($target, $siteId);

            // Null marks a super admin role, which bypasses every permission check.
            if ($held === null || array_diff_key($held, $this->actorPermissionNames($actor, $siteId) + $actorGlobal) !== []) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, true> */
    private function actorPermissionNames(Model $actor, ?int $teamId): array
    {
        $key = $actor->getMorphClass() . ':' . $actor->getKey();
        $team = $teamId === null ? '' : (string) $teamId;

        return $this->actorPermissions[$key][$team] ??= $this->permissionNames($actor, $teamId) ?? [];
    }

    /**
     * Permission names the user holds in one team, directly and through roles,
     * or null when the user holds the super admin role there or cannot be read.
     *
     * @return array<string, true>|null
     */
    private function permissionNames(Model $user, ?int $teamId): ?array
    {
        return PermissionTeamContext::run($teamId, function () use ($user): ?array {
            if (! method_exists($user, 'getAllPermissions') || ! method_exists($user, 'hasRole')) {
                return null;
            }

            // Loaded here, inside the team, because these models come from a table
            // query and Capell refuses lazy loading.
            $user->load(['roles.permissions', 'permissions']);

            if ($user->hasRole($this->superAdminRole())) {
                return null;
            }

            $names = [];

            foreach ($user->getAllPermissions() as $permission) {
                $names[(string) $permission->name] = true;
            }

            return $names;
        }, $user);
    }

    private function superAdminRole(): string
    {
        $configured = config('capell.roles.super_admin', config('filament-shield.super_admin.name', 'super_admin'));

        return is_string($configured) && $configured !== '' ? $configured : 'super_admin';
    }
}

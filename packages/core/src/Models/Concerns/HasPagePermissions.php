<?php

declare(strict_types=1);

namespace Capell\Core\Models\Concerns;

use Capell\Core\Models\PageRoleRestriction;
use Capell\Core\Models\Site;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Page-type-level role restrictions.
 *
 * When a type has rows in page_role_restrictions, only admin users who hold
 * at least one of those roles (within the site scope) may view or edit
 * pages using that blueprint. Blueprints with no restrictions are accessible to everyone
 * who can access the site.
 */
trait HasPagePermissions
{
    /** @return MorphMany<PageRoleRestriction, $this> */
    public function roleRestrictions(): MorphMany
    {
        return $this->morphMany(PageRoleRestriction::class, 'restrictable');
    }

    /**
     * Returns the role IDs that are allowed to access pages of this type.
     * An empty collection means no restrictions — all site users may access.
     *
     * @return Collection<int, int>
     */
    public function getRestrictedRoleIds(): Collection
    {
        return $this->roleRestrictions->pluck('role_id');
    }

    /** Returns true when this type has any role restrictions configured. */
    public function isRoleRestricted(): bool
    {
        return $this->roleRestrictions->isNotEmpty();
    }

    /**
     * Returns true when the given user is allowed to access pages of this type.
     * - If the type has no restrictions, every site-accessible user is allowed.
     * - If restricted, the user must hold at least one of the listed roles
     *   within the current site scope.
     */
    public function isAccessibleByUser(User $user, ?Site $site = null): bool
    {
        if (! $this->isRoleRestricted()) {
            return true;
        }

        $restrictedRoleIds = $this->getRestrictedRoleIds();

        if ($site instanceof Site) {
            return $this->userHasRestrictedRoleForSite($user, $restrictedRoleIds, $site);
        }

        return $user->roles
            ->pluck('id')
            ->intersect($restrictedRoleIds)
            ->isNotEmpty();
    }

    /**
     * Sync the type's role restrictions from an array of role IDs.
     *
     * @param  array<int>  $roleIds
     */
    public function syncRoleRestrictions(array $roleIds): void
    {
        $this->roleRestrictions()->whereNotIn('role_id', $roleIds)->delete();

        $existing = $this->roleRestrictions()->pluck('role_id')->all();

        $toInsert = array_diff($roleIds, $existing);

        foreach ($toInsert as $roleId) {
            $this->roleRestrictions()->create(['role_id' => $roleId]);
        }

        $this->unsetRelation('roleRestrictions');
    }

    /**
     * @param  Collection<int, int>  $restrictedRoleIds
     */
    private function userHasRestrictedRoleForSite(User $user, Collection $restrictedRoleIds, Site $site): bool
    {
        if ($restrictedRoleIds->isEmpty()) {
            return false;
        }

        $tableNames = config('permission.table_names', []);
        $modelHasRolesTable = is_array($tableNames) && is_string($tableNames['model_has_roles'] ?? null)
            ? $tableNames['model_has_roles']
            : 'model_has_roles';
        $teamColumnConfig = config('permission.column_names.team_foreign_key', 'team_id');
        $teamColumn = is_string($teamColumnConfig) && $teamColumnConfig !== '' ? $teamColumnConfig : 'team_id';

        return DB::table($modelHasRolesTable)
            ->where('model_type', $user->getMorphClass())
            ->where('model_id', $user->getKey())
            ->whereIn('role_id', $restrictedRoleIds->all())
            ->where(function (QueryBuilder $query) use ($teamColumn, $site): void {
                $query->whereNull($teamColumn)
                    ->orWhere($teamColumn, $site->getKey());
            })
            ->exists();
    }
}

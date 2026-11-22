<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Users;

use Capell\Core\Support\Permissions\SiteAccess;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\User;

/**
 * Which user accounts an actor may see, and which of those a site manager may
 * attach to a site. The Users resource and the site permissions workflow share
 * this one rule so an account is never reachable through one and hidden from
 * the other.
 */
final class SiteUserVisibility
{
    /**
     * A global actor sees every account. Any other actor sees themselves and
     * the accounts holding a role on a site the actor can access.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function scope(Builder $query, ?Authenticatable $actor): Builder
    {
        $access = SiteAccess::forActor($actor);

        if ($access->isGlobal()) {
            return $query;
        }

        if (! $actor instanceof Authenticatable) {
            return $query->whereRaw('1 = 0');
        }

        $pivot = (string) config('permission.table_names.model_has_roles', 'model_has_roles');
        $team = (string) config('permission.column_names.team_foreign_key', 'team_id');

        return $query->where(fn (Builder $users): Builder => $users
            ->whereKey($actor->getAuthIdentifier())
            ->orWhereHas('roles', fn (Builder $roles): Builder => $roles->whereIn($pivot . '.' . $team, $access->allowedSiteIds() ?? [])));
    }

    /**
     * The accounts a site manager may attach to a site: those visible to them,
     * never an account holding an installation-wide role. A global actor may
     * attach anyone.
     *
     * @param  class-string<User>  $userModel
     * @return Builder<User>
     */
    public static function attachableQuery(string $userModel, Authenticatable $actor): Builder
    {
        $query = self::scope(self::root($userModel), $actor);

        if (SiteAccess::forActor($actor)->isGlobal()) {
            return $query;
        }

        return $query->when(
            method_exists($userModel, 'scopeWithoutGlobalRoles'),
            fn (Builder $users): Builder => $users->scopes(['withoutGlobalRoles']),
        );
    }

    /**
     * The accounts to offer in a site's access picker: everyone the actor may
     * attach, plus the named current members so existing rows stay readable.
     *
     * @param  class-string<User>  $userModel
     * @param  list<int>  $memberIds
     * @return Builder<User>
     */
    public static function pickerQuery(string $userModel, Authenticatable $actor, array $memberIds): Builder
    {
        $key = (new $userModel)->getQualifiedKeyName();

        return self::root($userModel)->where(fn (Builder $users): Builder => $users
            ->whereIn($key, self::attachableQuery($userModel, $actor)->select($key))
            ->orWhereIn($key, $memberIds));
    }

    /**
     * Whether a specific account is one the actor may never attach or change.
     */
    public static function isGlobalAccount(User $user): bool
    {
        return SiteAccess::forActor($user)->isGlobal();
    }

    /**
     * @param  class-string<User>  $userModel
     * @return Builder<User>
     */
    private static function root(string $userModel): Builder
    {
        return (new $userModel)->newQuery();
    }
}

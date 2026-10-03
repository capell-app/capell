<?php

declare(strict_types=1);

namespace Capell\Admin\Support;

use Capell\Core\Models\Site;
use Capell\Core\Support\Permissions\SiteAccess;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class SiteScope
{
    /**
     * The legacy flag is retained for named-argument compatibility. Missing
     * actors are always denied, including when the old opt-out is supplied.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function applyForCurrentActor(Builder $query, string $column = 'site_id', bool $denyWhenMissingActor = true): Builder
    {
        return SiteAccess::current()->scope($query, $column);
    }

    public static function actorCanUseSite(?Authenticatable $actor, Site $site): bool
    {
        return SiteAccess::forActor($actor)->can($site);
    }

    public static function isGlobalActor(Authenticatable $actor): bool
    {
        return SiteAccess::forActor($actor)->isGlobal();
    }
}

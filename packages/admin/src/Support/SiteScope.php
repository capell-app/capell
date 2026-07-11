<?php

declare(strict_types=1);

namespace Capell\Admin\Support;

use Capell\Core\Models\Site;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;

final class SiteScope
{
    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function applyForCurrentActor(Builder $query, string $column = 'site_id', bool $denyWhenMissingActor = false): Builder
    {
        $actor = auth()->user();

        if (! $actor instanceof Authenticatable) {
            return $denyWhenMissingActor ? $query->whereRaw('1 = 0') : $query;
        }

        if (self::isGlobalActor($actor)) {
            return $query;
        }

        $assignedSiteIds = $actor->getAssignedSiteIds();

        return $assignedSiteIds->isNotEmpty()
            ? $query->whereIn($column, $assignedSiteIds)
            : $query->whereRaw('1 = 0');
    }

    public static function actorCanUseSite(?Authenticatable $actor, Site $site): bool
    {
        if (! $actor instanceof Authenticatable) {
            return false;
        }

        if (self::isGlobalActor($actor)) {
            return true;
        }

        return $actor->getAssignedSiteIds()->contains($site->getKey());
    }

    public static function isGlobalActor(Authenticatable $actor): bool
    {
        if (method_exists($actor, 'isGlobalAdmin')) {
            return $actor->isGlobalAdmin();
        }

        $configured = config('capell.roles.super_admin', config('filament-shield.super_admin.name', 'super_admin'));
        $superAdminRole = is_string($configured) && $configured !== '' ? $configured : 'super_admin';

        // Eloquent models are callable through __call(), so method_exists() is the safer runtime guard here.
        if (! method_exists($actor, 'hasRole')) {
            return false;
        }

        return $actor->hasRole($superAdminRole);
    }
}

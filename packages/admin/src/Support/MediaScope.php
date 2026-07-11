<?php

declare(strict_types=1);

namespace Capell\Admin\Support;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Media;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

final class MediaScope
{
    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function applyForCurrentActor(Builder $query): Builder
    {
        $actor = auth()->user();

        if (! $actor instanceof Authenticatable) {
            return $query->whereRaw('1 = 0');
        }

        if (SiteScope::isGlobalActor($actor)) {
            return $query;
        }

        $assignedSiteIds = $actor->getAssignedSiteIds();

        if ($assignedSiteIds->isEmpty()) {
            return $query->where(function (Builder $nestedQuery): void {
                $nestedQuery->whereHasMorph(
                    'model',
                    [Layout::class],
                    fn (Builder $ownerQuery): Builder => $ownerQuery->whereNull('site_id'),
                );
            });
        }

        return $query->where(function (Builder $nestedQuery) use ($assignedSiteIds): void {
            $nestedQuery
                ->whereHasMorph(
                    'model',
                    [
                        ...CapellCore::getPageVariationModels(),
                        Site::class,
                        Layout::class,
                    ],
                    function (Builder $ownerQuery, string $ownerType) use ($assignedSiteIds): Builder {
                        $ownerClass = Relation::getMorphedModel($ownerType) ?? $ownerType;

                        if (is_a($ownerClass, Site::class, true)) {
                            return $ownerQuery->whereIn('id', $assignedSiteIds);
                        }

                        if (is_a($ownerClass, Layout::class, true)) {
                            return $ownerQuery->where(
                                fn (Builder $layoutQuery): Builder => $layoutQuery
                                    ->whereNull('site_id')
                                    ->orWhereIn('site_id', $assignedSiteIds),
                            );
                        }

                        return $ownerQuery->whereIn('site_id', $assignedSiteIds);
                    },
                )
                ->orWhereHasMorph(
                    'model',
                    [Translation::class],
                    fn (Builder $translationQuery): Builder => $translationQuery->whereHasMorph(
                        'translatable',
                        [
                            ...CapellCore::getPageVariationModels(),
                            Site::class,
                        ],
                        function (Builder $translatableQuery, string $translatableType) use ($assignedSiteIds): Builder {
                            $translatableClass = Relation::getMorphedModel($translatableType) ?? $translatableType;

                            if (is_a($translatableClass, Site::class, true)) {
                                return $translatableQuery->whereIn('id', $assignedSiteIds);
                            }

                            return $translatableQuery->whereIn('site_id', $assignedSiteIds);
                        },
                    ),
                );
        });
    }

    public static function actorCanUseMedia(?Authenticatable $actor, Media $media): bool
    {
        if (! $actor instanceof Authenticatable) {
            return false;
        }

        if (SiteScope::isGlobalActor($actor)) {
            return true;
        }

        $media->loadMissing('model');
        $owner = $media->model;

        return self::actorCanUseOwner($actor, $owner);
    }

    private static function actorCanUseOwner(Authenticatable $actor, Model $owner): bool
    {
        if ($owner instanceof Site) {
            return SiteScope::actorCanUseSite($actor, $owner);
        }

        if ($owner instanceof Pageable) {
            $site = $owner->site()->first();

            return $site instanceof Site && SiteScope::actorCanUseSite($actor, $site);
        }

        if ($owner instanceof Layout) {
            if ($owner->site_id === null) {
                return true;
            }

            $owner->loadMissing('site');

            return $owner->site !== null && SiteScope::actorCanUseSite($actor, $owner->site);
        }

        if ($owner instanceof Translation) {
            $owner->loadMissing('translatable');
            $translatable = $owner->translatable;

            return self::actorCanUseOwner($actor, $translatable);
        }

        return false;
    }
}

<?php

declare(strict_types=1);

namespace Capell\Admin\Support;

use Capell\Core\Models\AssetAttachment;
use Capell\Core\Models\Media;
use Capell\Core\Support\Permissions\SiteAccess;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

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
        return SiteAccess::current()->scopeMedia($query);
    }

    /** @param Builder<AssetAttachment> $query
     * @return Builder<AssetAttachment>
     */
    public static function applyAssetAttachmentsForCurrentActor(Builder $query): Builder
    {
        return SiteAccess::current()->scopeAssetAttachments($query);
    }

    public static function trackedUsageCount(Media $media): int
    {
        return SiteAccess::current()->trackedUsageCount($media);
    }

    public static function isGlobalActor(): bool
    {
        return SiteAccess::current()->isGlobal();
    }

    public static function actorCanUseMedia(?Authenticatable $actor, Media $media): bool
    {
        return SiteAccess::forActor($actor)->canUseMedia($media);
    }
}

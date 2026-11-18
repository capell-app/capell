<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\View;

use Capell\Core\Models\PageUrl;
use Capell\Core\Models\SiteDomain;
use Illuminate\Support\Collection;

final class PublicPageRelations
{
    public static function loaded(?object $page, string $relation): ?object
    {
        if ($page === null || ! method_exists($page, 'relationLoaded') || ! $page->relationLoaded($relation)) {
            return null;
        }

        return $page->{$relation} ?? null;
    }

    public static function image(object $asset): mixed
    {
        if (! method_exists($asset, 'relationLoaded')) {
            return null;
        }

        if ($asset->relationLoaded('image')) {
            return $asset->image ?? null;
        }

        $media = self::loaded($asset, 'media');

        return $media instanceof Collection ? $media->first() : null;
    }

    public static function fullUrl(?object $page): ?string
    {
        return self::loadedUrl(self::loaded($page, 'pageUrl'));
    }

    public static function loadedUrl(?object $url): ?string
    {
        // PageUrl::fullUrl() would query for a missing domain while rendering.
        if ($url instanceof PageUrl && (! $url->relationLoaded('siteDomain') || ! $url->siteDomain instanceof SiteDomain)) {
            return null;
        }

        return $url->full_url ?? null;
    }
}

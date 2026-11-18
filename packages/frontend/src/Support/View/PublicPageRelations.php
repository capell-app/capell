<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\View;

use Capell\Core\Models\PageUrl;
use Capell\Core\Models\SiteDomain;

final class PublicPageRelations
{
    public static function loaded(?object $page, string $relation): ?object
    {
        if ($page === null || ! method_exists($page, 'relationLoaded') || ! $page->relationLoaded($relation)) {
            return null;
        }

        return $page->{$relation} ?? null;
    }

    public static function fullUrl(?object $page): ?string
    {
        $url = self::loaded($page, 'pageUrl');
        // PageUrl::fullUrl() would query for a missing domain while rendering.
        if ($url instanceof PageUrl && (! $url->relationLoaded('siteDomain') || ! $url->siteDomain instanceof SiteDomain)) {
            return null;
        }

        return $url->full_url ?? null;
    }
}

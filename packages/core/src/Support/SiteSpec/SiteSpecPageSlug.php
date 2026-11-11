<?php

declare(strict_types=1);

namespace Capell\Core\Support\SiteSpec;

use Capell\Core\Data\SiteSpec\CapellSiteSpecPageData;

/**
 * The slug a SiteSpec page is stored under. A page may name its own slug in its
 * meta, which wins over the spec slug, so every place that finds a page by slug
 * must use this one rule.
 */
final class SiteSpecPageSlug
{
    public static function for(CapellSiteSpecPageData $page): string
    {
        $slug = $page->meta['slug'] ?? null;

        return is_string($slug) && $slug !== '' ? $slug : ltrim($page->slug, '/');
    }
}

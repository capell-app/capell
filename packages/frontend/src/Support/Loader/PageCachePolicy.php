<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Loader;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Enums\CacheTime;
use Capell\Core\Models\Blueprint;
use Illuminate\Database\Eloquent\Model;

class PageCachePolicy
{
    /**
     * A page may be stored and served to other visitors only when its type was
     * loaded with it and allows caching. This runs while a page renders, so it
     * never queries: a type that was not loaded means the page is not cached.
     */
    public static function shouldCache(?Pageable $page): bool
    {
        if (! $page instanceof Model) {
            return false;
        }

        $blueprint = self::loadedBlueprint($page);

        return $blueprint instanceof Blueprint && $blueprint->cache_time !== CacheTime::Never;
    }

    /**
     * A page type set to never be cached renders visitor-specific output, so nothing
     * built for one request may be stored for, or reused by, another. A page that
     * names a type which was not loaded with it gives no answer, and is treated the
     * same way: the loader that resolves a page loads its type, and this never queries.
     */
    public static function neverCached(mixed $page): bool
    {
        if (! $page instanceof Model) {
            return false;
        }

        $blueprint = self::loadedBlueprint($page);

        if ($blueprint instanceof Blueprint) {
            return $blueprint->cache_time === CacheTime::Never;
        }

        return $page->getAttribute('blueprint_id') !== null;
    }

    private static function loadedBlueprint(Model $page): ?Blueprint
    {
        $blueprint = $page->relationLoaded('blueprint') ? $page->getRelation('blueprint') : null;

        return $blueprint instanceof Blueprint ? $blueprint : null;
    }
}

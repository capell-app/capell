<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Loader;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Enums\CacheTime;
use Capell\Core\Models\Blueprint;
use Illuminate\Database\Eloquent\Model;

class PageCachePolicy
{
    public static function shouldCache(?Pageable $page): bool
    {
        if (! $page instanceof Pageable) {
            return false;
        }

        if (! $page instanceof Model || ! $page->relationLoaded('blueprint')) {
            return false;
        }

        return ! self::neverCached($page);
    }

    /**
     * A page type set to never be cached renders visitor-specific output, so nothing
     * built for one request may be stored for, or reused by, another.
     */
    public static function neverCached(mixed $page): bool
    {
        if (! $page instanceof Model || ! $page->relationLoaded('blueprint')) {
            return false;
        }

        $blueprint = $page->getRelation('blueprint');

        return $blueprint instanceof Blueprint && $blueprint->cache_time === CacheTime::Never;
    }
}

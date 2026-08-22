<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Loader;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Enums\CacheTime;
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

        return $page->blueprint?->cache_time !== CacheTime::Never;
    }
}

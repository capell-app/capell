<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Cache;

use Capell\Core\Concerns\HasCache;
use Capell\Frontend\Data\PageListingSpec;
use Capell\Frontend\Enums\CacheEnum;
use Closure;

final class PageListingCache
{
    use HasCache;

    /**
     * Fetch the ordered page IDs for the given spec, populating the cache when
     * it is absent. The $loader closure must return int[].
     *
     * @param  Closure(): int[]  $loader
     * @return int[]
     */
    public function getIds(PageListingSpec $spec, Closure $loader): array
    {
        $generation = $this->currentGeneration($spec->siteId ?? 0, $spec->languageId);
        $key = CacheEnum::pageIds($spec->toCacheKey(), $generation);

        /** @var int[]|null $cached */
        $cached = $this->rememberCache($key, fn (): array => $loader());

        return $cached ?? [];
    }

    /**
     * Invalidate all listing ID caches for a given site + language by bumping
     * the generation counter. Existing cache entries are abandoned in place and
     * will expire at their natural TTL.
     */
    public function invalidateListings(int $siteId, int $languageId): void
    {
        $this->incrementCacheKey(CacheEnum::listingGeneration($siteId, $languageId));
    }

    private function currentGeneration(int $siteId, int $languageId): int
    {
        $genKey = CacheEnum::listingGeneration($siteId, $languageId);
        $value = $this->getFromCache($genKey);

        return is_int($value) ? $value : 0;
    }
}

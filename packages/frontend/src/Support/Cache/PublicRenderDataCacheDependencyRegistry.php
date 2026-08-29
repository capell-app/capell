<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Cache;

use Capell\Frontend\Data\CacheInvalidationRule;
use Capell\Frontend\Data\PublicRenderDataCacheDependencyData;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Eloquent\Model;

/** Persists the exact public render cache destinations touched by a model. */
final class PublicRenderDataCacheDependencyRegistry
{
    private const string CACHE_PREFIX = 'capell.frontend.public-render-dependencies:';

    public function __construct(private readonly Repository $cache) {}

    public function register(PublicRenderDataCacheDependencyData $dependency, string $cacheKey): void
    {
        $indexKey = $this->indexKey($dependency);
        $cacheKeys = $this->cache->get($indexKey, []);

        if (! is_array($cacheKeys)) {
            $cacheKeys = [];
        }

        if (! in_array($cacheKey, $cacheKeys, true)) {
            $cacheKeys[] = $cacheKey;
            $this->cache->forever($indexKey, $cacheKeys);
        }
    }

    /** @return list<CacheInvalidationRule> */
    public function rulesFor(Model $model): array
    {
        $dependency = PublicRenderDataCacheDependencyData::forModel($model);
        $cacheKeys = $this->cache->get($this->indexKey($dependency), []);

        if (! is_array($cacheKeys)) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $cacheKey): CacheInvalidationRule => CacheInvalidationRule::forgetKey((string) $cacheKey),
            array_filter($cacheKeys, static fn (mixed $cacheKey): bool => is_string($cacheKey) && $cacheKey !== ''),
        ));
    }

    public function forget(Model $model): void
    {
        $this->cache->forget($this->indexKey(PublicRenderDataCacheDependencyData::forModel($model)));
    }

    private function indexKey(PublicRenderDataCacheDependencyData $dependency): string
    {
        return self::CACHE_PREFIX . hash('sha256', $dependency->identity());
    }
}

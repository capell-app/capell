<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Cache;

use Capell\Core\Support\Cache\CapellCacheManager;
use Capell\Frontend\Data\CacheInvalidationRule;
use Capell\Frontend\Data\PublicRenderDataCacheDependencyData;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/** Persists the exact public render cache destinations touched by a model. */
final class PublicRenderDataCacheDependencyRegistry
{
    private const string CACHE_PREFIX = 'capell.frontend.public-render-dependencies:';

    public function __construct(private readonly CapellCacheManager $cache) {}

    public function register(PublicRenderDataCacheDependencyData $dependency, string $cacheKey): void
    {
        $this->withLock($dependency, function () use ($dependency, $cacheKey): void {
            $indexKey = $this->indexKey($dependency);
            $cacheKeys = $this->cache->getFromCache($indexKey);
            $cacheKeys = is_array($cacheKeys) ? $cacheKeys : [];

            if (! in_array($cacheKey, $cacheKeys, true)) {
                $cacheKeys[] = $cacheKey;
                $this->cache->setToCache($indexKey, $cacheKeys, 0);
            }
        });
    }

    /** @return list<CacheInvalidationRule> */
    public function rulesFor(Model $model): array
    {
        return $this->withLock(PublicRenderDataCacheDependencyData::forModel($model), fn (): array => $this->rulesForUnlocked(PublicRenderDataCacheDependencyData::forModel($model)));
    }

    public function forget(Model $model): void
    {
        $dependency = PublicRenderDataCacheDependencyData::forModel($model);
        $this->withLock($dependency, function () use ($dependency): void {
            $this->cache->removeCacheKey($this->indexKey($dependency));
        });
    }

    /** Keep registration, invalidation, and index cleanup atomic. */
    public function invalidate(Model $model, Closure $callback): void
    {
        $dependency = PublicRenderDataCacheDependencyData::forModel($model);

        $this->withLock($dependency, function () use ($dependency, $callback): void {
            $callback($this->rulesForUnlocked($dependency));
            $this->cache->removeCacheKey($this->indexKey($dependency));
        });
    }

    private function indexKey(PublicRenderDataCacheDependencyData $dependency): string
    {
        return self::CACHE_PREFIX . hash('sha256', $dependency->identity());
    }

    /** @return list<CacheInvalidationRule> */
    private function rulesForUnlocked(PublicRenderDataCacheDependencyData $dependency): array
    {
        $cacheKeys = $this->cache->getFromCache($this->indexKey($dependency));

        if (! is_array($cacheKeys)) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $cacheKey): CacheInvalidationRule => CacheInvalidationRule::forgetKey((string) $cacheKey),
            array_filter($cacheKeys, static fn (mixed $cacheKey): bool => is_string($cacheKey) && $cacheKey !== ''),
        ));
    }

    private function withLock(PublicRenderDataCacheDependencyData $dependency, Closure $callback): mixed
    {
        return Cache::lock(
            'capell.frontend.public-render-dependency.' . hash('sha256', $dependency->identity()),
            30,
        )->block(10, $callback);
    }
}

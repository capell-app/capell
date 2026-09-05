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
            $this->registerUnlocked($dependency, $cacheKey);
        });
    }

    /** @param list<string> $surrogateKeys */
    public function registerStaticArtifact(PublicRenderDataCacheDependencyData $dependency, string $file, array $surrogateKeys = []): void
    {
        $this->withLock($dependency, function () use ($dependency, $file, $surrogateKeys): void {
            $this->registerDestinationUnlocked($this->staticIndexKey($dependency), $file);
            $this->registerSurrogateKeysUnlocked($dependency, $surrogateKeys);
        });
    }

    /**
     * @param  list<PublicRenderDataCacheDependencyData>  $dependencies
     * @param  list<string>  $surrogateKeys
     */
    public function writeStaticArtifact(array $dependencies, string $file, array $surrogateKeys, Closure $write): void
    {
        $unique = [];
        foreach ($dependencies as $dependency) {
            $unique[$dependency->identity()] = $dependency;
        }

        ksort($unique);
        $dependencies = array_values($unique);

        $this->withDependencyLocks($dependencies, 0, function () use ($dependencies, $file, $surrogateKeys, $write): void {
            foreach ($dependencies as $dependency) {
                $this->registerDestinationUnlocked($this->staticIndexKey($dependency), $file);
                $this->registerSurrogateKeysUnlocked($dependency, $surrogateKeys);
            }

            // Hold the same locks as invalidation until the file is on disk.
            $write();
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
            $this->cache->removeCacheKey($this->staticIndexKey($dependency));
            $this->cache->removeCacheKey($this->surrogateIndexKey($dependency));
        });
    }

    /** Keep registration, invalidation, and index cleanup atomic. */
    public function invalidate(Model $model, Closure $callback): void
    {
        $dependency = PublicRenderDataCacheDependencyData::forModel($model);

        $this->withLock($dependency, function () use ($dependency, $callback): void {
            $callback($this->rulesForUnlocked($dependency));
            $this->cache->removeCacheKey($this->indexKey($dependency));
            $this->cache->removeCacheKey($this->staticIndexKey($dependency));
            $this->cache->removeCacheKey($this->surrogateIndexKey($dependency));
        });
    }

    /** @param list<PublicRenderDataCacheDependencyData> $dependencies */
    public function remember(array $dependencies, string $cacheKey, Closure $callback): mixed
    {
        usort($dependencies, static fn (PublicRenderDataCacheDependencyData $left, PublicRenderDataCacheDependencyData $right): int => $left->identity() <=> $right->identity());

        return $this->withDependencyLocks($dependencies, 0, function () use ($dependencies, $cacheKey, $callback): mixed {
            foreach ($dependencies as $dependency) {
                $this->registerUnlocked($dependency, $cacheKey);
            }

            return $callback();
        });
    }

    private function indexKey(PublicRenderDataCacheDependencyData $dependency): string
    {
        return self::CACHE_PREFIX . hash('sha256', $dependency->identity());
    }

    private function staticIndexKey(PublicRenderDataCacheDependencyData $dependency): string
    {
        return self::CACHE_PREFIX . 'static:' . hash('sha256', $dependency->identity());
    }

    private function surrogateIndexKey(PublicRenderDataCacheDependencyData $dependency): string
    {
        return self::CACHE_PREFIX . 'surrogates:' . hash('sha256', $dependency->identity());
    }

    /** @return list<CacheInvalidationRule> */
    private function rulesForUnlocked(PublicRenderDataCacheDependencyData $dependency): array
    {
        $cacheKeys = $this->cache->getFreshFromCache($this->indexKey($dependency));

        $cacheKeys = is_array($cacheKeys) ? $cacheKeys : [];

        $rules = array_values(array_map(
            static fn (mixed $cacheKey): CacheInvalidationRule => CacheInvalidationRule::forgetKey((string) $cacheKey),
            array_filter($cacheKeys, static fn (mixed $cacheKey): bool => is_string($cacheKey) && $cacheKey !== ''),
        ));

        $staticFiles = $this->cache->getFreshFromCache($this->staticIndexKey($dependency));
        if (is_array($staticFiles)) {
            foreach ($staticFiles as $file) {
                if (is_string($file) && $file !== '') {
                    $rules[] = CacheInvalidationRule::staticArtifact($file);
                }
            }
        }

        $surrogateKeys = $this->cache->getFreshFromCache($this->surrogateIndexKey($dependency));
        if (is_array($surrogateKeys) && $surrogateKeys !== []) {
            $rules[] = CacheInvalidationRule::surrogateKeys(array_values(array_filter($surrogateKeys, static fn (mixed $key): bool => is_string($key) && $key !== '')));
        }

        return $rules;
    }

    private function registerUnlocked(PublicRenderDataCacheDependencyData $dependency, string $cacheKey): void
    {
        $this->registerDestinationUnlocked($this->indexKey($dependency), $cacheKey);
    }

    private function registerDestinationUnlocked(string $indexKey, string $destination): void
    {
        $cacheKeys = $this->cache->getFreshFromCache($indexKey);
        $cacheKeys = is_array($cacheKeys) ? $cacheKeys : [];

        if (! in_array($destination, $cacheKeys, true)) {
            $cacheKeys[] = $destination;
            $this->cache->setToCache($indexKey, $cacheKeys, 0);
        }
    }

    /** @param list<string> $surrogateKeys */
    private function registerSurrogateKeysUnlocked(PublicRenderDataCacheDependencyData $dependency, array $surrogateKeys): void
    {
        foreach (array_values(array_unique($surrogateKeys)) as $surrogateKey) {
            $this->registerDestinationUnlocked($this->surrogateIndexKey($dependency), $surrogateKey);
        }
    }

    /** @param list<PublicRenderDataCacheDependencyData> $dependencies */
    private function withDependencyLocks(array $dependencies, int $index, Closure $callback): mixed
    {
        if (! isset($dependencies[$index])) {
            return $callback();
        }

        return $this->withLock($dependencies[$index], fn (): mixed => $this->withDependencyLocks($dependencies, $index + 1, $callback));
    }

    private function withLock(PublicRenderDataCacheDependencyData $dependency, Closure $callback): mixed
    {
        return Cache::lock(
            'capell.frontend.public-render-dependency.' . hash('sha256', $dependency->identity()),
            30,
        )->block(10, $callback);
    }
}

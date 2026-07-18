<?php

declare(strict_types=1);

namespace Capell\Core\Concerns;

use BackedEnum;
use Capell\Core\Support\Cache\CapellCacheManager;
use Closure;
use DateInterval;
use DateTimeInterface;

trait HasCache
{
    public function rememberCache(
        string|BackedEnum $key,
        Closure $callback,
        Closure|DateTimeInterface|DateInterval|int|null $ttl = null,
    ): mixed {
        return resolve(CapellCacheManager::class)->rememberCache($key, $callback, $ttl);
    }

    public function getFromCache(string $key): mixed
    {
        return resolve(CapellCacheManager::class)->getFromCache($key);
    }

    public function setToCache(string $key, mixed $value, Closure|DateTimeInterface|DateInterval|int|null $ttl = null): void
    {
        resolve(CapellCacheManager::class)->setToCache($key, $value, $ttl);
    }

    public function cacheExists(string $key): bool
    {
        return resolve(CapellCacheManager::class)->cacheExists($key);
    }

    public function removeCacheKey(string $key): void
    {
        resolve(CapellCacheManager::class)->removeCacheKey($key);
    }

    public function incrementCacheKey(string $key): int
    {
        return resolve(CapellCacheManager::class)->incrementCacheKey($key);
    }

    public function flushCache(): void
    {
        resolve(CapellCacheManager::class)->flushCache();
    }

    public function flushLocalCache(): void
    {
        resolve(CapellCacheManager::class)->flushLocalCache();
    }
}

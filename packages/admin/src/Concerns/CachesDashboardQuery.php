<?php

declare(strict_types=1);

namespace Capell\Admin\Concerns;

use Illuminate\Support\Facades\Cache;

/**
 * Provides caching support for dashboard Filament widget queries.
 *
 * This trait helps reduce database load by caching query results for a configurable TTL.
 * Widgets can use this to cache health checks and other expensive queries.
 */
trait CachesDashboardQuery
{
    /**
     * Cache a query result using the given callback.
     *
     * If the cache key exists, the cached value is returned immediately.
     * If the key does not exist, the callback is executed, its result is cached,
     * and then returned.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function cacheQueryResult(callable $callback, string $cacheKey): mixed
    {
        if (Cache::has($cacheKey)) {
            /** @var T $cached */
            $cached = Cache::get($cacheKey);

            return $cached;
        }

        $result = $callback();
        Cache::put($cacheKey, $result, $this->dashboardCacheTtl());

        return $result;
    }

    /**
     * Clear the cache for a specific widget.
     */
    public function clearCacheForWidget(string $cacheKey): void
    {
        Cache::forget($cacheKey);
    }

    /**
     * Get the default cache TTL for dashboard queries in seconds.
     *
     * Override this method in your widget to customize the TTL.
     * Default is 5 minutes (300 seconds).
     */
    protected function dashboardCacheTtl(): int
    {
        return 300; // 5 minutes
    }
}

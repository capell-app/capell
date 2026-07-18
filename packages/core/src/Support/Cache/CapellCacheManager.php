<?php

declare(strict_types=1);

namespace Capell\Core\Support\Cache;

use BackedEnum;
use Capell\Core\Support\Database\RuntimeSchemaState;
use Closure;
use DateInterval;
use DateTimeInterface;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class CapellCacheManager
{
    /**
     * In-memory, per-request cache to avoid repeated backend calls for the
     * same cache key during a single request lifecycle.
     *
     * Stored values follow the same sentinel convention as the backend store
     * (i.e. the sentinel string represents a persisted null).
     *
     * @var array<string, mixed>
     */
    protected array $localCache = [];

    public function rememberCache(
        string|BackedEnum $key,
        Closure $callback,
        Closure|DateTimeInterface|DateInterval|int|null $ttl = null,
    ): mixed {
        if (config('capell-core.disable_cache') === true) {
            return $callback();
        }

        if ($key instanceof BackedEnum) {
            $key = (string) $key->value;
        }

        $normalizedKey = $this->normalizeCacheKey($key);

        $cache = $this->getCacheInstance();
        $sentinel = $this->getCacheSentinel();

        // Check per-request in-memory cache first to avoid repeated DB queries
        if (array_key_exists($normalizedKey, $this->localCache)) {
            $cached = $this->localCache[$normalizedKey];

            return $cached === $sentinel ? null : $cached;
        }

        $value = $cache->get($normalizedKey);

        // A deserialized value whose class cannot be found produces an
        // __PHP_Incomplete_Class instance. Discard it and treat the entry as a
        // cache miss so the callback can re-populate with the correct class.
        if (is_object($value) && $value::class === '__PHP_Incomplete_Class') {
            $this->getCacheInstance()->forget($normalizedKey);
            $value = null;
        }

        // Store raw backend value in local cache (use sentinel for persisted null)
        $this->localCache[$normalizedKey] = $value;

        if ($value === $sentinel) {
            return null;
        }

        if ($value !== null) {
            return $value;
        }

        $value = $callback();
        $ttl = $this->getCacheTtl($ttl);

        if ($this->isCacheSaveDisabledForKey($key)) {
            return $value;
        }

        $this->saveToCache($cache, $normalizedKey, $value, $ttl, $sentinel);

        // Keep local cache in sync with backend save (use sentinel for null)
        $this->localCache[$normalizedKey] = $value ?? $sentinel;

        return $value;
    }

    public function getFromCache(string $key): mixed
    {
        $normalizedKey = $this->normalizeCacheKey($key);
        $sentinel = $this->getCacheSentinel();

        if (array_key_exists($normalizedKey, $this->localCache)) {
            $cached = $this->localCache[$normalizedKey];

            return $cached === $sentinel ? null : $cached;
        }

        $cache = $this->getCacheInstance();
        $value = $cache->get($normalizedKey);

        $this->localCache[$normalizedKey] = $value;

        return $value === $sentinel ? null : $value;
    }

    public function setToCache(string $key, mixed $value, Closure|DateTimeInterface|DateInterval|int|null $ttl = null): void
    {
        if ($this->isCacheSaveDisabledForKey($key)) {
            return;
        }

        $normalizedKey = $this->normalizeCacheKey($key);
        $cache = $this->getCacheInstance();
        $sentinel = $this->getCacheSentinel();
        $ttl = $this->getCacheTtl($ttl);

        $this->saveToCache($cache, $normalizedKey, $value, $ttl, $sentinel);

        // Sync local cache
        $this->localCache[$normalizedKey] = $value ?? $sentinel;
    }

    public function cacheExists(string $key): bool
    {
        $normalizedKey = $this->normalizeCacheKey($key);
        $sentinel = $this->getCacheSentinel();

        if (array_key_exists($normalizedKey, $this->localCache)) {
            $cached = $this->localCache[$normalizedKey];

            return $cached !== null && $cached !== $sentinel;
        }

        $cache = $this->getCacheInstance();
        $value = $cache->get($normalizedKey);

        $this->localCache[$normalizedKey] = $value;

        return $value !== null && $value !== $sentinel;
    }

    public function removeCacheKey(string $key): void
    {
        $normalizedKey = $this->normalizeCacheKey($key);

        unset($this->localCache[$normalizedKey]);
        $this->getCacheInstance()->forget($normalizedKey);
    }

    public function flushCache(): void
    {
        $this->flushLocalCache();
        $store = Cache::store();

        // Prefer to flush using tags when available on the concrete store
        // instance. We use method_exists to keep this check dynamic so static
        // analysis cannot resolve it to a constant and raise unreachable code
        // warnings.
        try {
            $store->tags(config('capell-core.cache_tag', 'capell-app'))->flush();

            return;
        } catch (Throwable) {
            // Fall back to clear below.
        }

        $this->bumpCacheNamespaceGeneration();
    }

    /** @internal */
    public function flushLocalCache(): void
    {
        $this->localCache = [];
    }

    protected function getCacheInstance(): CacheRepository
    {
        if (! $this->configuredCacheStoreIsAvailable()) {
            return new Repository(new ArrayStore);
        }

        return Cache::supportsTags()
            ? Cache::tags(config('capell-core.cache_tag', 'capell-app'))
            : Cache::store();
    }

    protected function configuredCacheStoreIsAvailable(): bool
    {
        $storeName = config('cache.default');

        if (! is_string($storeName) || $storeName === '') {
            return true;
        }

        $driver = config(sprintf('cache.stores.%s.driver', $storeName));

        if ($driver !== 'database') {
            return true;
        }

        $table = config(sprintf('cache.stores.%s.table', $storeName), 'cache');

        if (! is_string($table) || $table === '') {
            return true;
        }

        return resolve(RuntimeSchemaState::class)->hasTable($table);
    }

    protected function getCacheSentinel(): string
    {
        return '__capell_null__';
    }

    protected function getCacheTtl(Closure|DateTimeInterface|DateInterval|int|null $ttl = null): DateTimeInterface|DateInterval|int
    {
        if ($ttl instanceof Closure) {
            $ttl = $ttl();
        }

        if ($ttl === null) {
            return config('capell.cache_ttl', 60);
        }

        return $ttl;
    }

    protected function saveToCache(CacheRepository $cache, string $key, mixed $value, DateTimeInterface|DateInterval|int $ttl, string $sentinel): void
    {
        if ($ttl === 0) { // 0 indicates forever storage when explicitly provided as int
            $cache->forever($key, $value ?? $sentinel);

            return;
        }

        $expiresAt = $ttl instanceof DateInterval
            ? now()->add($ttl)
            : $ttl; // int or DateTimeInterface

        $cache->put($key, $value ?? $sentinel, $expiresAt);
    }

    /**
     * Normalize cache key to fit storage constraints.
     * Always hash keys using sha256 for consistency and backend safety.
     */
    protected function normalizeCacheKey(string $key): string
    {
        // Hash long/complex keys to ensure backend compatibility (indexes, length,
        // allowed characters) and keep a consistent fixed-length key.
        return hash('sha256', $this->cacheNamespaceGeneration() . '|' . $key);
    }

    protected function cacheNamespaceGeneration(): int
    {
        if (! $this->configuredCacheStoreIsAvailable()) {
            return 0;
        }

        if (Cache::supportsTags()) {
            return 0;
        }

        $request = app()->bound('request') ? resolve('request') : null;
        $requestCacheKey = 'capell.cache.generation.' . config('capell-core.cache_tag', 'capell-app');

        if ($request instanceof Request && $request->attributes->has($requestCacheKey)) {
            return (int) $request->attributes->get($requestCacheKey);
        }

        $generation = (int) Cache::store()->get($this->cacheNamespaceGenerationKey(), 0);

        if ($request instanceof Request) {
            $request->attributes->set($requestCacheKey, $generation);
        }

        return $generation;
    }

    protected function bumpCacheNamespaceGeneration(): void
    {
        $this->flushLocalCache();
        if (! $this->configuredCacheStoreIsAvailable()) {
            return;
        }

        $generation = $this->incrementRawCacheKey($this->cacheNamespaceGenerationKey());
        $request = app()->bound('request') ? resolve('request') : null;

        if ($request instanceof Request) {
            $request->attributes->set(
                'capell.cache.generation.' . config('capell-core.cache_tag', 'capell-app'),
                $generation,
            );
        }
    }

    protected function cacheNamespaceGenerationKey(): string
    {
        return 'capell.cache.generation.' . config('capell-core.cache_tag', 'capell-app');
    }

    protected function incrementCacheKey(string $key): int
    {
        $normalizedKey = $this->normalizeCacheKey($key);

        unset($this->localCache[$normalizedKey]);

        $cache = $this->getCacheInstance();

        return $this->withCacheIncrementLock(
            'normalized:' . $normalizedKey,
            fn (): int => $this->incrementRepositoryKey($cache, $normalizedKey),
        );
    }

    protected function incrementRawCacheKey(string $key): int
    {
        return $this->withCacheIncrementLock(
            'raw:' . $key,
            fn (): int => $this->incrementStoreKey($key),
        );
    }

    /**
     * Determine whether saving to cache is disabled for a given key.
     * Uses config('capell-core.disable_cache_save_keys'), which may be:
     *  - array of exact strings
     *  - array of regex patterns (prefixed and suffixed with '/')
     *  - array of wildcards using '*' (e.g., 'page-*')
     */
    protected function isCacheSaveDisabledForKey(string $key): bool
    {
        $rules = config('capell-core.disable_cache_save_keys', []);

        if (! is_array($rules) || $rules === []) {
            return false;
        }

        foreach ($rules as $rule) {
            if (! is_string($rule)) {
                continue;
            }

            if ($rule === '') {
                continue;
            }

            // Regex rule: '/pattern/'
            if ($rule[0] === '/' && str_ends_with($rule, '/')) {
                if (@preg_match($rule, $key) === 1) {
                    return true;
                }

                continue;
            }

            if (str_contains($rule, '*')) {
                $pattern = '/^' . str_replace('\*', '.*', preg_quote($rule, '/')) . '$/';
                if (preg_match($pattern, $key) === 1) {
                    return true;
                }

                continue;
            }

            // Exact match
            if ($key === $rule) {
                return true;
            }
        }

        return false;
    }

    private function incrementRepositoryKey(CacheRepository $cache, string $key): int
    {
        $cache->add($key, 0);
        $incremented = $cache->increment($key);

        if (is_int($incremented)) {
            return $incremented;
        }

        $fallback = ((int) $cache->get($key, 0)) + 1;
        $this->saveToCache($cache, $key, $fallback, 0, $this->getCacheSentinel());

        return $fallback;
    }

    private function incrementStoreKey(string $key): int
    {
        $cache = Cache::store();
        $cache->add($key, 0);

        $incremented = $cache->increment($key);

        if (is_int($incremented)) {
            return $incremented;
        }

        $fallback = ((int) $cache->get($key, 0)) + 1;
        $cache->forever($key, $fallback);

        return $fallback;
    }

    private function withCacheIncrementLock(string $key, Closure $callback): int
    {
        if ($this->cacheStoreHasAtomicIncrement()) {
            return $callback();
        }

        try {
            return Cache::lock('capell.cache.increment.' . hash('sha256', $key), 10)
                ->block(5, $callback);
        } catch (Throwable) {
            return $callback();
        }
    }

    private function cacheStoreHasAtomicIncrement(): bool
    {
        $storeName = config('cache.default');

        if (! is_string($storeName) || $storeName === '') {
            return false;
        }

        $driver = config(sprintf('cache.stores.%s.driver', $storeName));

        return in_array($driver, ['redis', 'memcached', 'dynamodb'], true);
    }
}

<?php

declare(strict_types=1);

namespace Capell\Core\Support\Cache;

use BackedEnum;
use Capell\Core\Data\Cache\CacheRuntimeDiagnosticsData;
use Capell\Core\Support\Database\RuntimeSchemaState;
use Closure;
use DateInterval;
use DateTimeInterface;
use Error;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use LogicException;
use Throwable;

final class CapellCacheManager
{
    private const int SAMPLED_KEY_LIMIT = 8;

    private const int TELEMETRY_TTL_SECONDS = 86400;

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

    /** @var array<string, true> */
    private array $cacheInvalidationPatterns = [];

    /** @var array<string, int> */
    private array $cacheInvalidationPatternGenerations = [];

    private int $cacheHitCount = 0;

    private int $cacheMissCount = 0;

    private int $cacheFillCount = 0;

    private int $cacheBackendFailureCount = 0;

    /** @var list<string> */
    private array $sampledKeyHashes = [];

    public function __construct()
    {
        app()->terminating(fn () => $this->persistRuntimeDiagnostics());
    }

    public function rememberCache(
        string|BackedEnum $key,
        Closure $callback,
        Closure|DateTimeInterface|DateInterval|int|null $ttl = null,
    ): mixed {
        if (config('capell.disable_cache') === true) {
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

            $this->recordCacheRead($key, $cached !== null);

            return $cached === $sentinel ? null : $cached;
        }

        try {
            $value = $cache->get($normalizedKey);
        } catch (Throwable $throwable) {
            $this->rethrowProgrammingFailure($throwable);
            $this->cacheBackendFailureCount++;
            $this->cacheMissCount++;
            $this->sampleCacheKey($key);

            return $this->resolveUncached($normalizedKey, $callback, $sentinel);
        }

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
            $this->recordCacheRead($key, true);

            return null;
        }

        if ($value !== null) {
            $this->recordCacheRead($key, true);

            return $value;
        }

        $this->recordCacheRead($key, false);

        $ttl = $this->getCacheTtl($ttl);

        if ($this->isCacheSaveDisabledForKey($key)) {
            unset($this->localCache[$normalizedKey]);
            $resolved = $callback();
            $this->cacheFillCount++;

            return $resolved;
        }

        $value = $this->rememberCacheMiss($cache, $normalizedKey, $callback, $ttl, $sentinel);

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

            $this->recordCacheRead($key, $cached !== null);

            return $cached === $sentinel ? null : $cached;
        }

        $cache = $this->getCacheInstance();
        try {
            $value = $cache->get($normalizedKey);
        } catch (Throwable $throwable) {
            $this->rethrowProgrammingFailure($throwable);
            $this->cacheBackendFailureCount++;
            $this->recordCacheRead($key, false);

            return null;
        }

        $this->localCache[$normalizedKey] = $value;
        $this->recordCacheRead($key, $value !== null);

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

        try {
            $this->saveToCache($cache, $normalizedKey, $value, $ttl, $sentinel);
        } catch (Throwable $throwable) {
            $this->rethrowProgrammingFailure($throwable);
            $this->cacheBackendFailureCount++;
        }

        // Sync local cache
        $this->localCache[$normalizedKey] = $value ?? $sentinel;
    }

    public function cacheExists(string $key): bool
    {
        $normalizedKey = $this->normalizeCacheKey($key);
        $sentinel = $this->getCacheSentinel();

        if (array_key_exists($normalizedKey, $this->localCache)) {
            $cached = $this->localCache[$normalizedKey];

            $this->recordCacheRead($key, $cached !== null);

            return $cached !== null && $cached !== $sentinel;
        }

        $cache = $this->getCacheInstance();
        try {
            $value = $cache->get($normalizedKey);
        } catch (Throwable $throwable) {
            $this->rethrowProgrammingFailure($throwable);
            $this->cacheBackendFailureCount++;
            $this->recordCacheRead($key, false);

            return false;
        }

        $this->localCache[$normalizedKey] = $value;
        $this->recordCacheRead($key, $value !== null);

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
            $store->tags(config('capell.cache_tag', 'capell-app'))->flush();

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
        $this->cacheInvalidationPatternGenerations = [];
    }

    public function flushRuntimeState(): void
    {
        $this->persistRuntimeDiagnostics();
        $this->flushLocalCache();
        $this->cacheHitCount = 0;
        $this->cacheMissCount = 0;
        $this->cacheFillCount = 0;
        $this->cacheBackendFailureCount = 0;
        $this->sampledKeyHashes = [];
    }

    public function runtimeDiagnostics(): CacheRuntimeDiagnosticsData
    {
        $storeName = config('cache.default');
        $storeName = is_string($storeName) && $storeName !== '' ? $storeName : 'default';

        $driver = config(sprintf('cache.stores.%s.driver', $storeName));

        $persisted = $this->persistedRuntimeDiagnostics();

        return new CacheRuntimeDiagnosticsData(
            enabled: config('capell.disable_cache') !== true,
            backendReachable: $this->cacheBackendIsReachable(),
            store: $storeName,
            driver: is_string($driver) && $driver !== '' ? $driver : 'unknown',
            hitCount: $persisted['hits'] + $this->cacheHitCount,
            missCount: $persisted['misses'] + $this->cacheMissCount,
            fillCount: $persisted['fills'] + $this->cacheFillCount,
            backendFailureCount: $persisted['backend_failures'] + $this->cacheBackendFailureCount,
            sampledKeyHashes: array_values(array_slice(array_unique([
                ...$persisted['sampled_key_hashes'],
                ...$this->sampledKeyHashes,
            ]), 0, self::SAMPLED_KEY_LIMIT)),
        );
    }

    public function registerCacheInvalidationPattern(string $pattern): void
    {
        if ($pattern !== '' && str_contains($pattern, '*')) {
            $this->cacheInvalidationPatterns[$pattern] = true;
        }
    }

    public function invalidateCachePattern(string $pattern): void
    {
        $this->registerCacheInvalidationPattern($pattern);

        if (! isset($this->cacheInvalidationPatterns[$pattern])) {
            return;
        }

        $generation = $this->incrementRawCacheKey($this->cacheInvalidationPatternGenerationKey($pattern));
        $this->localCache = [];
        $this->cacheInvalidationPatternGenerations[$pattern] = $generation;
    }

    public function incrementCacheKey(string $key): int
    {
        $normalizedKey = $this->normalizeCacheKey($key);

        unset($this->localCache[$normalizedKey]);

        $cache = $this->getCacheInstance();

        return $this->withCacheIncrementLock(
            'normalized:' . $normalizedKey,
            fn (): int => $this->incrementRepositoryKey($cache, $normalizedKey),
        );
    }

    private function rememberCacheMiss(
        CacheRepository $cache,
        string $normalizedKey,
        Closure $callback,
        DateTimeInterface|DateInterval|int $ttl,
        string $sentinel,
    ): mixed {
        $callbackStarted = false;
        $lockSeconds = max(1, (int) config('capell.cache_lock_seconds', 30));
        $lockWaitSeconds = max(
            $lockSeconds + 1,
            (int) config('capell.cache_lock_wait_seconds', 10),
        );

        try {
            return Cache::lock(
                'capell.cache.remember.' . $normalizedKey,
                $lockSeconds,
            )->block(
                $lockWaitSeconds,
                function () use ($cache, $normalizedKey, $callback, $ttl, $sentinel, &$callbackStarted): mixed {
                    $cached = $cache->get($normalizedKey);

                    if ($cached === $sentinel) {
                        return null;
                    }

                    if ($cached !== null && (! is_object($cached) || $cached::class !== '__PHP_Incomplete_Class')) {
                        return $cached;
                    }

                    if (is_object($cached) && $cached::class === '__PHP_Incomplete_Class') {
                        $cache->forget($normalizedKey);
                    }

                    $callbackStarted = true;
                    $resolved = $callback();
                    $this->cacheFillCount++;

                    try {
                        $this->saveToCache($cache, $normalizedKey, $resolved, $ttl, $sentinel);
                    } catch (Throwable $throwable) {
                        $this->rethrowProgrammingFailure($throwable);
                        $this->cacheBackendFailureCount++;
                    }

                    return $resolved;
                },
            );
        } catch (Throwable $throwable) {
            throw_if($callbackStarted, $throwable);

            $this->cacheBackendFailureCount++;

            try {
                $cached = $cache->get($normalizedKey);
            } catch (Throwable $throwable) {
                $this->rethrowProgrammingFailure($throwable);
                $this->cacheBackendFailureCount++;
                $cached = null;
            }

            if ($cached === $sentinel) {
                return null;
            }

            if ($cached !== null && (! is_object($cached) || $cached::class !== '__PHP_Incomplete_Class')) {
                return $cached;
            }

            $resolved = $callback();
            $this->cacheFillCount++;

            try {
                $this->saveToCache($cache, $normalizedKey, $resolved, $ttl, $sentinel);
            } catch (Throwable $throwable) {
                $this->rethrowProgrammingFailure($throwable);
                $this->cacheBackendFailureCount++;
            }

            return $resolved;
        }
    }

    private function getCacheInstance(): CacheRepository
    {
        if (! $this->configuredCacheStoreIsAvailable()) {
            return new Repository(new ArrayStore);
        }

        return Cache::supportsTags()
            ? Cache::tags(config('capell.cache_tag', 'capell-app'))
            : Cache::store();
    }

    private function configuredCacheStoreIsAvailable(): bool
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

    private function getCacheSentinel(): string
    {
        return '__capell_null__';
    }

    private function getCacheTtl(Closure|DateTimeInterface|DateInterval|int|null $ttl = null): DateTimeInterface|DateInterval|int
    {
        if ($ttl instanceof Closure) {
            $ttl = $ttl();
        }

        if ($ttl === null) {
            return config('capell.cache_ttl', 60);
        }

        return $ttl;
    }

    private function saveToCache(CacheRepository $cache, string $key, mixed $value, DateTimeInterface|DateInterval|int $ttl, string $sentinel): void
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
    private function normalizeCacheKey(string $key): string
    {
        // Hash long/complex keys to ensure backend compatibility (indexes, length,
        // allowed characters) and keep a consistent fixed-length key.
        $patternGenerations = [];

        foreach (array_keys($this->cacheInvalidationPatterns) as $pattern) {
            if ($this->cacheKeyMatchesPattern($key, $pattern)) {
                $patternGenerations[$pattern] = $this->cacheInvalidationPatternGeneration($pattern);
            }
        }

        return hash('sha256', serialize([
            $this->cacheNamespaceGeneration(),
            $patternGenerations,
            $key,
        ]));
    }

    private function cacheKeyMatchesPattern(string $key, string $pattern): bool
    {
        return preg_match('/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/', $key) === 1;
    }

    private function cacheInvalidationPatternGeneration(string $pattern): int
    {
        if (array_key_exists($pattern, $this->cacheInvalidationPatternGenerations)) {
            return $this->cacheInvalidationPatternGenerations[$pattern];
        }

        try {
            $generation = (int) Cache::store()->get($this->cacheInvalidationPatternGenerationKey($pattern), 0);
        } catch (Throwable $throwable) {
            $this->rethrowProgrammingFailure($throwable);
            $this->cacheBackendFailureCount++;
            $generation = 0;
        }

        $this->cacheInvalidationPatternGenerations[$pattern] = $generation;

        return $generation;
    }

    private function cacheInvalidationPatternGenerationKey(string $pattern): string
    {
        return 'capell.cache.pattern-generation.' . hash('sha256', $pattern);
    }

    private function cacheNamespaceGeneration(): int
    {
        if (! $this->configuredCacheStoreIsAvailable()) {
            return 0;
        }

        if (Cache::supportsTags()) {
            return 0;
        }

        $request = app()->bound('request') ? resolve('request') : null;
        $requestCacheKey = 'capell.cache.generation.' . config('capell.cache_tag', 'capell-app');

        if ($request instanceof Request && $request->attributes->has($requestCacheKey)) {
            return (int) $request->attributes->get($requestCacheKey);
        }

        try {
            $generation = (int) Cache::store()->get($this->cacheNamespaceGenerationKey(), 0);
        } catch (Throwable $throwable) {
            $this->rethrowProgrammingFailure($throwable);
            $this->cacheBackendFailureCount++;
            $generation = 0;
        }

        if ($request instanceof Request) {
            $request->attributes->set($requestCacheKey, $generation);
        }

        return $generation;
    }

    private function bumpCacheNamespaceGeneration(): void
    {
        $this->flushLocalCache();
        if (! $this->configuredCacheStoreIsAvailable()) {
            return;
        }

        $generation = $this->incrementRawCacheKey($this->cacheNamespaceGenerationKey());
        $request = app()->bound('request') ? resolve('request') : null;

        if ($request instanceof Request) {
            $request->attributes->set(
                'capell.cache.generation.' . config('capell.cache_tag', 'capell-app'),
                $generation,
            );
        }
    }

    private function cacheNamespaceGenerationKey(): string
    {
        return 'capell.cache.generation.' . config('capell.cache_tag', 'capell-app');
    }

    private function incrementRawCacheKey(string $key): int
    {
        return $this->withCacheIncrementLock(
            'raw:' . $key,
            fn (): int => $this->incrementStoreKey($key),
        );
    }

    /**
     * Determine whether saving to cache is disabled for a given key.
     * Uses config('capell.disable_cache_save_keys'), which may be:
     *  - array of exact strings
     *  - array of regex patterns (prefixed and suffixed with '/')
     *  - array of wildcards using '*' (e.g., 'page-*')
     */
    private function isCacheSaveDisabledForKey(string $key): bool
    {
        $rules = config('capell.disable_cache_save_keys', []);

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

    private function resolveUncached(string $normalizedKey, Closure $callback, string $sentinel): mixed
    {
        $resolved = $callback();
        $this->cacheFillCount++;
        $this->localCache[$normalizedKey] = $resolved ?? $sentinel;

        return $resolved;
    }

    private function recordCacheRead(string $key, bool $hit): void
    {
        if ($hit) {
            $this->cacheHitCount++;
        } else {
            $this->cacheMissCount++;
        }

        $this->sampleCacheKey($key);
    }

    private function sampleCacheKey(string $key): void
    {
        if (count($this->sampledKeyHashes) >= self::SAMPLED_KEY_LIMIT) {
            return;
        }

        $hash = substr(hash('sha256', $key), 0, 16);

        if (! in_array($hash, $this->sampledKeyHashes, true)) {
            $this->sampledKeyHashes[] = $hash;
        }
    }

    private function cacheBackendIsReachable(): bool
    {
        if (! $this->configuredCacheStoreIsAvailable()) {
            return false;
        }

        $key = 'capell.cache.health.' . bin2hex(random_bytes(8));
        try {
            $cache = Cache::store();

            if (! $cache->put($key, 'reachable', 10)) {
                return false;
            }

            if ($cache->get($key) !== 'reachable') {
                return false;
            }

            return $cache->forget($key);
        } catch (Throwable $throwable) {
            $this->rethrowProgrammingFailure($throwable);
            $this->cacheBackendFailureCount++;

            return false;
        }
    }

    private function rethrowProgrammingFailure(Throwable $throwable): void
    {
        throw_if($throwable instanceof Error || $throwable instanceof LogicException, $throwable);
    }

    private function persistRuntimeDiagnostics(): void
    {
        if ($this->cacheHitCount === 0
            && $this->cacheMissCount === 0
            && $this->cacheFillCount === 0
            && $this->cacheBackendFailureCount === 0
            && $this->sampledKeyHashes === []) {
            return;
        }

        try {
            $cache = Cache::store();

            foreach ($this->runtimeDiagnosticCounters() as $name => $value) {
                if ($value === 0) {
                    continue;
                }

                $key = $this->runtimeDiagnosticKey($name);
                $cache->add($key, 0, self::TELEMETRY_TTL_SECONDS);
                $cache->increment($key, $value);
            }

            $samplesKey = $this->runtimeDiagnosticKey('sampled_key_hashes');
            $samples = $cache->get($samplesKey, []);
            $samples = is_array($samples) ? $samples : [];
            $cache->put(
                $samplesKey,
                array_values(array_slice(array_unique([...$samples, ...$this->sampledKeyHashes]), 0, self::SAMPLED_KEY_LIMIT)),
                self::TELEMETRY_TTL_SECONDS,
            );
        } catch (Throwable $throwable) {
            $this->rethrowProgrammingFailure($throwable);
        }
    }

    /** @return array{hits: int, misses: int, fills: int, backend_failures: int} */
    private function runtimeDiagnosticCounters(): array
    {
        return [
            'hits' => $this->cacheHitCount,
            'misses' => $this->cacheMissCount,
            'fills' => $this->cacheFillCount,
            'backend_failures' => $this->cacheBackendFailureCount,
        ];
    }

    /** @return array{hits: int, misses: int, fills: int, backend_failures: int, sampled_key_hashes: list<string>} */
    private function persistedRuntimeDiagnostics(): array
    {
        try {
            $cache = Cache::store();
            $samples = $cache->get($this->runtimeDiagnosticKey('sampled_key_hashes'), []);

            return [
                'hits' => (int) $cache->get($this->runtimeDiagnosticKey('hits'), 0),
                'misses' => (int) $cache->get($this->runtimeDiagnosticKey('misses'), 0),
                'fills' => (int) $cache->get($this->runtimeDiagnosticKey('fills'), 0),
                'backend_failures' => (int) $cache->get($this->runtimeDiagnosticKey('backend_failures'), 0),
                'sampled_key_hashes' => is_array($samples)
                    ? array_values(array_filter($samples, is_string(...)))
                    : [],
            ];
        } catch (Throwable $throwable) {
            $this->rethrowProgrammingFailure($throwable);

            return [
                'hits' => 0,
                'misses' => 0,
                'fills' => 0,
                'backend_failures' => 0,
                'sampled_key_hashes' => [],
            ];
        }
    }

    private function runtimeDiagnosticKey(string $metric): string
    {
        return 'capell.cache.runtime.' . $metric;
    }
}

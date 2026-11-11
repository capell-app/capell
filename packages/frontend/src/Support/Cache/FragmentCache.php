<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Cache;

use Capell\Core\Support\Cache\CacheOrigin;
use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

final class FragmentCache
{
    private const int DEFAULT_TTL = 3600;

    private const int METADATA_TTL = 86400 * 30;

    private const string NAMESPACE_KEY = 'fragment:namespace';

    public function __construct(private readonly Repository $cache) {}

    /** @param list<string> $surrogateKeys */
    public function remember(
        string $key,
        callable $callback,
        int $ttlSeconds = self::DEFAULT_TTL,
        array $surrogateKeys = [],
    ): mixed {
        return $this->rememberVersioned($this->namespace(), $key, CacheOrigin::discriminator(), $callback, $ttlSeconds, $surrogateKeys);
    }

    public function invalidateBySurrogateKey(string $surrogateKey): void
    {
        $namespace = $this->namespace();

        // Entries published by this version are validated against a per-surrogate
        // token, so rotating it makes every dependent entry (and any render still
        // in flight) unservable in one write, with no lock and no enumeration.
        throw_unless(
            $this->cache->put($this->tokenKey($namespace, $this->surrogateId($surrogateKey)), $this->newToken(), self::METADATA_TTL),
            RuntimeException::class,
            'Unable to rotate the fragment surrogate token.',
        );

        $this->purgeLegacySurrogateMap($namespace, $surrogateKey);
    }

    public function flush(): void
    {
        $namespace = $this->namespace();
        // No lock is needed because the new namespace makes late old-namespace writes unreachable.
        $replacement = bin2hex(random_bytes(16));
        throw_unless(
            $this->cache->put(self::NAMESPACE_KEY, $replacement, self::METADATA_TTL)
            && $this->cache->get(self::NAMESPACE_KEY) === $replacement,
            RuntimeException::class,
            'Unable to rotate the fragment cache namespace.',
        );
        $this->cache->forget($this->mapKey($namespace));
    }

    /**
     * Entries written by a node still running the previous release live under
     * unversioned keys and are enumerated by a locked surrogate map. Keep purging
     * them until every node serves the versioned format.
     */
    private function purgeLegacySurrogateMap(string $namespace, string $surrogateKey): void
    {
        $mapKey = $this->mapKey($namespace);
        $fragmentKeys = [];
        if (! $this->withSurrogateMapLock($mapKey, function () use ($mapKey, $surrogateKey, &$fragmentKeys): void {
            $surrogateMap = $this->surrogateMap($mapKey);
            $fragmentKeys = $surrogateMap[$surrogateKey] ?? [];
            if ($fragmentKeys === []) {
                return;
            }

            foreach ($surrogateMap as $surrogate => $keys) {
                $remaining = array_values(array_diff($keys, $fragmentKeys));

                if ($remaining === []) {
                    unset($surrogateMap[$surrogate]);
                } else {
                    $surrogateMap[$surrogate] = $remaining;
                }
            }

            if ($surrogateMap === []) {
                $this->cache->forget($mapKey);
            } else {
                throw_unless($this->cache->put($mapKey, $surrogateMap, self::METADATA_TTL), RuntimeException::class, 'Unable to update the fragment surrogate map.');
            }
        })) {
            return;
        }

        // The map is detached before values are forgotten so the lock never spans
        // an unbounded number of cache deletions.
        foreach ($fragmentKeys as $fragmentKey) {
            try {
                $this->cache->forget($fragmentKey);
            } catch (Throwable) {
                // Cache invalidation is best effort; the next purge can retry it.
            }
        }
    }

    private function namespace(): string
    {
        $namespace = $this->cache->remember(self::NAMESPACE_KEY, self::METADATA_TTL, static fn (): string => bin2hex(random_bytes(16)));

        throw_unless(is_string($namespace), UnexpectedValueException::class, 'The fragment cache namespace must be a string.');

        return $namespace;
    }

    /**
     * Versioned, compare-and-set publication. The entry records the surrogate
     * tokens it was rendered against; a hit is valid only while every recorded
     * token is unchanged, and a render publishes only if no token moved while it
     * ran. A hit asking for surrogates the entry does not yet record extends the
     * entry's ownership rather than replacing it.
     *
     * @param  list<string>  $surrogateKeys
     */
    private function rememberVersioned(string $namespace, string $key, string $origin, callable $callback, int $ttlSeconds, array $surrogateKeys): mixed
    {
        $cacheKey = $origin === ''
            ? 'fragment:' . $namespace . ':v2:value:' . $key
            : 'fragment:' . $namespace . ':v2:origin-value:' . hash('sha256', serialize([$key, $origin]));

        $wanted = [];
        foreach ($surrogateKeys as $surrogate) {
            $wanted[$this->surrogateId($surrogate)] = true;
        }

        $entry = $this->cache->get($cacheKey);

        if (is_array($entry) && ($entry['v'] ?? null) === 2 && array_key_exists('value', $entry) && is_array($entry['tokens'] ?? null)) {
            $recorded = array_filter($entry['tokens'], is_string(...));

            if ($this->readTokens($namespace, array_keys($recorded)) === $recorded) {
                $missing = array_keys(array_diff_key($wanted, $recorded));

                if ($missing !== []) {
                    $this->extendEntry($namespace, $cacheKey, $entry['value'], $recorded, $missing, $ttlSeconds);
                }

                return $entry['value'];
            }
        }

        $ids = array_keys($wanted);
        $tokens = $this->ensureTokens($namespace, $ids);
        $result = $callback();

        if ($tokens !== null) {
            $this->publish($namespace, $cacheKey, $result, $tokens, $ttlSeconds);
        }

        return $result;
    }

    /**
     * @param  array<string, string>  $recorded
     * @param  list<string>  $missingIds
     */
    private function extendEntry(string $namespace, string $cacheKey, mixed $value, array $recorded, array $missingIds, int $ttlSeconds): void
    {
        $added = $this->ensureTokens($namespace, $missingIds);

        if ($added !== null) {
            $this->publish($namespace, $cacheKey, $value, $recorded + $added, $ttlSeconds);
        }
    }

    /** @param array<string, string> $tokens */
    private function publish(string $namespace, string $cacheKey, mixed $value, array $tokens, int $ttlSeconds): void
    {
        try {
            // Compare: a purge that ran meanwhile rotated at least one token.
            if ($this->readTokens($namespace, array_keys($tokens)) === $tokens) {
                $this->cache->put($cacheKey, ['v' => 2, 'value' => $value, 'tokens' => $tokens], $ttlSeconds);
            }
        } catch (Throwable) {
            // Do not turn a cache publication failure into a render failure.
        }
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, string>|null
     */
    private function ensureTokens(string $namespace, array $ids): ?array
    {
        try {
            foreach ($ids as $id) {
                // add() never overwrites, so concurrent first renders agree on one token.
                $this->cache->add($this->tokenKey($namespace, $id), $this->newToken(), self::METADATA_TTL);
            }

            return $this->readTokens($namespace, $ids);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, string>|null
     */
    private function readTokens(string $namespace, array $ids): ?array
    {
        $keys = [];
        foreach ($ids as $id) {
            $keys[(string) $id] = $this->tokenKey($namespace, (string) $id);
        }

        $current = $this->cache->many(array_values($keys));
        $tokens = [];

        foreach ($keys as $id => $tokenKey) {
            if (! is_string($current[$tokenKey] ?? null)) {
                return null;
            }

            $tokens[$id] = $current[$tokenKey];
        }

        return $tokens;
    }

    private function surrogateId(string $surrogate): string
    {
        return 'v2:surrogate:' . hash('sha256', $surrogate);
    }

    private function tokenKey(string $namespace, string $id): string
    {
        return 'fragment:' . $namespace . ':' . $id;
    }

    private function newToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function withSurrogateMapLock(string $mapKey, Closure $callback): bool
    {
        $store = $this->cache->getStore();
        if (! $store instanceof LockProvider) {
            return false;
        }

        try {
            $store->lock('fragment:surrogate:lock:' . hash('sha256', $mapKey), 30)->block(1, $callback);

            return true;
        } catch (Throwable) {
            // Fragment caching must fail open for the public response. Callers
            // publish only from inside this section, so a failed lock cannot
            // leave a value that the surrogate map does not enumerate.
            return false;
        }
    }

    private function mapKey(string $namespace): string
    {
        return 'fragment:' . $namespace . ':surrogate:map';
    }

    /** @return array<array-key, list<string>> */
    private function surrogateMap(string $mapKey): array
    {
        $surrogateMap = $this->cache->get($mapKey, []);

        if (! is_array($surrogateMap)) {
            return [];
        }

        $validated = [];

        foreach ($surrogateMap as $surrogate => $keys) {
            // PHP stores numeric string keys as integers, including "0".
            if (is_array($keys)) {
                $validated[$surrogate] = array_values(array_filter($keys, is_string(...)));
            }
        }

        return $validated;
    }
}

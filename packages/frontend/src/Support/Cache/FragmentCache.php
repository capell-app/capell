<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Cache;

use Capell\Core\Support\Cache\CacheOrigin;
use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use RuntimeException;
use stdClass;
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
        $namespace = $this->namespace();
        $rendered = false;
        $renderedResult = null;
        $render = static function () use ($callback, &$rendered, &$renderedResult): mixed {
            $rendered = true;

            return $renderedResult = $callback();
        };
        $result = $this->rememberInNamespace($namespace, $key, $render, $ttlSeconds, $surrogateKeys);

        /**
         * The surrounding page was hydrated before the flush too. Return this
         * request's callback output without publishing into the new namespace;
         * the next request will hydrate afresh.
         */
        if ($this->namespace() !== $namespace) {
            return $rendered ? $renderedResult : $render();
        }

        return $result;
    }

    public function invalidateBySurrogateKey(string $surrogateKey): void
    {
        // Writers never trust the request memory: another process may have
        // rotated the namespace since this request first read it.
        $mapKey = $this->mapKey($this->namespace());
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

    /** @param list<string> $surrogateKeys */
    private function rememberInNamespace(string $namespace, string $key, callable $callback, int $ttlSeconds, array $surrogateKeys): mixed
    {
        $origin = CacheOrigin::discriminator();
        // Keep standard-port keys byte-identical. A separate physical prefix
        // prevents arbitrary logical keys from aliasing non-standard variants.
        $cacheKey = $origin === ''
            ? 'fragment:' . $namespace . ':value:' . $key
            : 'fragment:' . $namespace . ':origin-value:' . hash('sha256', serialize([$key, $origin]));

        $missing = new stdClass;
        $entry = $this->cache->get($cacheKey, $missing);
        if ($entry instanceof FragmentCacheEntry) {
            if (array_diff($surrogateKeys, $entry->surrogateKeys) === []) {
                return $entry->value;
            }

            $result = $entry->value;
        } elseif ($entry !== $missing) {
            if ($surrogateKeys === []) {
                return $entry;
            }

            $result = $entry;
        } else {
            $result = $callback();
            if ($this->namespace() !== $namespace) {
                return $result;
            }
        }

        if ($surrogateKeys === []) {
            $this->cache->put($cacheKey, $result, $ttlSeconds);
        } else {
            $renderedResult = $result;
            $published = $this->publishTrackedFragment($namespace, $cacheKey, $result, $ttlSeconds, $surrogateKeys, $missing);
            if (! $published) {
                return $entry === $missing ? $renderedResult : $callback();
            }
        }

        return $result;
    }

    private function namespace(): string
    {
        $namespace = $this->cache->remember(self::NAMESPACE_KEY, self::METADATA_TTL, static fn (): string => bin2hex(random_bytes(16)));

        throw_unless(is_string($namespace), UnexpectedValueException::class, 'The fragment cache namespace must be a string.');

        return $namespace;
    }

    /**
     * Publish a rendered surrogate-bearing fragment by updating its membership
     * before writing the value under the shared map lock. A failed map write
     * therefore cannot leave a value that surrogate invalidation cannot find.
     *
     * @param  list<string>  $surrogateKeys
     */
    private function publishTrackedFragment(string $namespace, string $fragmentKey, mixed &$result, int $ttlSeconds, array $surrogateKeys, stdClass $missing): bool
    {
        $mapKey = $this->mapKey($namespace);
        $published = false;
        $this->withSurrogateMapLock($mapKey, function () use ($namespace, $mapKey, $fragmentKey, &$result, $ttlSeconds, $surrogateKeys, $missing, &$published): void {
            if ($this->namespace() !== $namespace) {
                return;
            }

            $existing = $this->cache->get($fragmentKey, $missing);
            $membership = $surrogateKeys;
            if ($existing instanceof FragmentCacheEntry) {
                $result = $existing->value;
                $membership = array_values(array_unique([...$existing->surrogateKeys, ...$surrogateKeys]));
            } elseif ($existing !== $missing) {
                $result = $existing;
            }

            try {
                $surrogateMap = $this->surrogateMap($mapKey);
                if (! $this->hasSurrogateMembership($surrogateMap, $fragmentKey, $membership)) {
                    $this->registerSurrogateKeys($mapKey, $fragmentKey, $membership, $surrogateMap);
                }

                if ($this->namespace() !== $namespace) {
                    $this->cache->forget($mapKey);

                    return;
                }

                if ($this->namespace() === $namespace) {
                    throw_unless($this->cache->put($fragmentKey, new FragmentCacheEntry($result, $membership), $ttlSeconds), RuntimeException::class, 'Unable to store the fragment value.');
                    $published = true;
                }
            } catch (Throwable) {
                // Cache publication is optional for the public response.
            }
        });

        return $published;
    }

    /**
     * @param  list<string>  $surrogateKeys
     * @param  array<array-key, list<string>>  $surrogateMap
     */
    private function registerSurrogateKeys(string $mapKey, string $fragmentKey, array $surrogateKeys, array $surrogateMap): void
    {
        foreach ($surrogateKeys as $surrogate) {
            if (! isset($surrogateMap[$surrogate])) {
                $surrogateMap[$surrogate] = [];
            }

            if (! in_array($fragmentKey, $surrogateMap[$surrogate], true)) {
                $surrogateMap[$surrogate][] = $fragmentKey;
            }
        }

        throw_unless($this->cache->put($mapKey, $surrogateMap, self::METADATA_TTL), RuntimeException::class, 'Unable to update the fragment surrogate map.');
    }

    /** @param list<string> $surrogateKeys */
    private function hasSurrogateMembership(array $surrogateMap, string $fragmentKey, array $surrogateKeys): bool
    {
        return array_all($surrogateKeys, fn (string $surrogate): bool => in_array($fragmentKey, $surrogateMap[$surrogate] ?? [], true));
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

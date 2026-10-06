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
        $origin = CacheOrigin::discriminator();
        // Keep standard-port keys byte-identical. A separate physical prefix
        // prevents arbitrary logical keys from aliasing non-standard variants.
        $cacheKey = $origin === ''
            ? 'fragment:' . $namespace . ':value:' . $key
            : 'fragment:' . $namespace . ':origin-value:' . hash('sha256', serialize([$key, $origin]));

        if ($surrogateKeys === []) {
            return $this->cache->remember($cacheKey, $ttlSeconds, $callback);
        }

        $missing = new stdClass;
        $result = $this->cache->get($cacheKey, $missing);
        if ($result !== $missing) {
            $surrogateMap = $this->surrogateMap($this->mapKey($namespace));
            if ($this->hasSurrogateMembership($surrogateMap, $cacheKey, $surrogateKeys)) {
                return $result;
            }

        } else {
            $result = $callback();
        }

        $this->publishTrackedFragment($namespace, $cacheKey, $result, $ttlSeconds, $surrogateKeys, $missing);

        return $result;
    }

    public function invalidateBySurrogateKey(string $surrogateKey): void
    {
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
        $this->cache->put(self::NAMESPACE_KEY, bin2hex(random_bytes(16)), self::METADATA_TTL);
        $this->cache->forget($this->mapKey($namespace));
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
    private function publishTrackedFragment(string $namespace, string $fragmentKey, mixed &$result, int $ttlSeconds, array $surrogateKeys, stdClass $missing): void
    {
        $mapKey = $this->mapKey($namespace);
        $this->withSurrogateMapLock($mapKey, function () use ($mapKey, $fragmentKey, &$result, $ttlSeconds, $surrogateKeys, $missing): void {
            $existing = $this->cache->get($fragmentKey, $missing);
            $newFragment = $existing === $missing;
            if (! $newFragment) {
                $result = $existing;
            }

            try {
                $surrogateMap = $this->surrogateMap($mapKey);
                if (! $this->hasSurrogateMembership($surrogateMap, $fragmentKey, $surrogateKeys)) {
                    $this->registerSurrogateKeys($mapKey, $fragmentKey, $surrogateKeys, $surrogateMap);
                }

                if ($newFragment) {
                    throw_unless($this->cache->put($fragmentKey, $result, $ttlSeconds), RuntimeException::class, 'Unable to store the fragment value.');
                }
            } catch (Throwable) {
                // Do not turn a cache publication failure into a render failure.
            }
        });
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

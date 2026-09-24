<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Cache;

use Illuminate\Contracts\Cache\Repository;
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
        $cacheKey = 'fragment:' . $namespace . ':value:' . $key;

        $result = $this->cache->remember($cacheKey, $ttlSeconds, $callback);

        // Store surrogate keys for this fragment so it can be invalidated
        if ($surrogateKeys !== []) {
            $this->storeSurrogateKeysForFragment($namespace, $cacheKey, $surrogateKeys);
        }

        return $result;
    }

    public function invalidateBySurrogateKey(string $surrogateKey): void
    {
        $mapKey = $this->mapKey($this->namespace());
        $surrogateMap = $this->surrogateMap($mapKey);
        $fragmentKeys = $surrogateMap[$surrogateKey] ?? [];

        foreach ($fragmentKeys as $fragmentKey) {
            $this->cache->forget($fragmentKey);
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
            $this->cache->put($mapKey, $surrogateMap, self::METADATA_TTL);
        }
    }

    public function flush(): void
    {
        $namespace = $this->namespace();
        // Rotating the namespace also invalidates untagged and concurrently written
        // fragments without a store-wide flush or a racy global key inventory.
        $this->cache->put(self::NAMESPACE_KEY, bin2hex(random_bytes(16)), self::METADATA_TTL);
        $this->cache->forget($this->mapKey($namespace));
    }

    private function namespace(): string
    {
        $namespace = $this->cache->remember(self::NAMESPACE_KEY, self::METADATA_TTL, static fn (): string => bin2hex(random_bytes(16)));

        throw_unless(is_string($namespace), UnexpectedValueException::class, 'The fragment cache namespace must be a string.');

        return $namespace;
    }

    /** @param list<string> $surrogateKeys */
    private function storeSurrogateKeysForFragment(string $namespace, string $fragmentKey, array $surrogateKeys): void
    {
        $mapKey = $this->mapKey($namespace);
        $surrogateMap = $this->surrogateMap($mapKey);

        foreach ($surrogateKeys as $surrogate) {
            if (! isset($surrogateMap[$surrogate])) {
                $surrogateMap[$surrogate] = [];
            }

            if (! in_array($fragmentKey, $surrogateMap[$surrogate], true)) {
                $surrogateMap[$surrogate][] = $fragmentKey;
            }
        }

        $this->cache->put($mapKey, $surrogateMap, self::METADATA_TTL);
    }

    private function mapKey(string $namespace): string
    {
        return 'fragment:' . $namespace . ':surrogate:map';
    }

    /** @return array<string, list<string>> */
    private function surrogateMap(string $mapKey): array
    {
        $surrogateMap = $this->cache->get($mapKey, []);

        if (! is_array($surrogateMap)) {
            return [];
        }

        $validated = [];

        foreach ($surrogateMap as $surrogate => $keys) {
            if (is_string($surrogate) && is_array($keys)) {
                $validated[$surrogate] = array_values(array_filter($keys, is_string(...)));
            }
        }

        return $validated;
    }
}

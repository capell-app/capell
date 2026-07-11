<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Cache;

use Illuminate\Contracts\Cache\Repository;

final class FragmentCache
{
    private const int DEFAULT_TTL = 3600;

    public function __construct(private readonly Repository $cache) {}

    public function remember(
        string $key,
        callable $callback,
        int $ttlSeconds = self::DEFAULT_TTL,
        array $surrogateKeys = [],
    ): mixed {
        $cacheKey = $this->normalizeCacheKey($key);

        $result = $this->cache->remember($cacheKey, $ttlSeconds, $callback);

        // Store surrogate keys for this fragment so it can be invalidated
        if ($surrogateKeys !== []) {
            $this->storeSurrogateKeysForFragment($cacheKey, $surrogateKeys);
        }

        return $result;
    }

    public function invalidateBySurrogateKey(string $surrogateKey): void
    {
        $fragmentKeys = $this->getFragmentsBySurrogateKey($surrogateKey);

        foreach ($fragmentKeys as $fragmentKey) {
            $this->cache->forget($fragmentKey);
        }
    }

    public function flush(): void
    {
        // Flush all fragment cache by deleting known patterns
        // In production, this would use a cache store that supports tagging
        $this->cache->flush();
    }

    private function normalizeCacheKey(string $key): string
    {
        return 'fragment:' . $key;
    }

    private function storeSurrogateKeysForFragment(string $fragmentKey, array $surrogateKeys): void
    {
        $mapKey = 'fragment:surrogate:map';
        $surrogateMap = $this->cache->get($mapKey, []);

        foreach ($surrogateKeys as $surrogate) {
            if (! isset($surrogateMap[$surrogate])) {
                $surrogateMap[$surrogate] = [];
            }

            if (! in_array($fragmentKey, $surrogateMap[$surrogate], true)) {
                $surrogateMap[$surrogate][] = $fragmentKey;
            }
        }

        $this->cache->put($mapKey, $surrogateMap, 86400 * 30);
    }

    private function getFragmentsBySurrogateKey(string $surrogateKey): array
    {
        $mapKey = 'fragment:surrogate:map';
        $surrogateMap = $this->cache->get($mapKey, []);

        return $surrogateMap[$surrogateKey] ?? [];
    }
}

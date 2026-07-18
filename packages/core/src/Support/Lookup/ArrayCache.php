<?php

declare(strict_types=1);

namespace Capell\Core\Support\Lookup;

use BackedEnum;
use Illuminate\Support\Facades\Cache;

final class ArrayCache
{
    private const string REGISTRY_KEY = 'capell-core-cache-keys';

    public function remember(string $key, callable $resolver, bool $asBool = false): mixed
    {
        $cached = Cache::driver('array')->get($key);
        if ($cached !== null) {
            return $asBool ? (bool) $cached : $cached;
        }

        $result = $resolver();
        Cache::driver('array')->forever($key, $result);
        $this->track($key);

        return $asBool ? (bool) $result : $result;
    }

    public function forget(string $key): void
    {
        Cache::driver('array')->forget($key);
    }

    /**
     * @param  array<int, string|BackedEnum>|string|BackedEnum|null  $prefixes
     */
    public function flush(null|string|array|BackedEnum $prefixes = null): int
    {
        $driver = Cache::driver('array');
        $registry = $driver->get(self::REGISTRY_KEY, []);

        if ($prefixes === null) {
            foreach ($registry as $key) {
                $driver->forget($key);
            }

            $driver->forget(self::REGISTRY_KEY);

            return count($registry);
        }

        $normalized = collect((array) $prefixes)
            ->map(fn (string|BackedEnum $prefix): int|string => $prefix instanceof BackedEnum ? $prefix->value : $prefix)
            ->values()
            ->all();

        $remaining = [];
        $flushed = 0;

        foreach ($registry as $key) {
            $shouldFlush = false;
            foreach ($normalized as $prefix) {
                if (str_starts_with((string) $key, (string) $prefix)) {
                    $shouldFlush = true;
                    break;
                }
            }

            if ($shouldFlush) {
                $driver->forget($key);
                $flushed++;
            } else {
                $remaining[] = $key;
            }
        }

        $driver->forever(self::REGISTRY_KEY, $remaining);

        return $flushed;
    }

    private function track(string $key): void
    {
        $registry = Cache::driver('array')->get(self::REGISTRY_KEY, []);
        if (in_array($key, $registry, true)) {
            return;
        }

        $registry[] = $key;
        Cache::driver('array')->forever(self::REGISTRY_KEY, $registry);
    }
}

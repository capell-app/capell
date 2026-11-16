<?php

declare(strict_types=1);

namespace Capell\Core\Support\Cache;

use Illuminate\Database\QueryException;
use Override;
use Spatie\LaravelData\Support\Caching\CachedDataConfig;
use Spatie\LaravelData\Support\Caching\DataStructureCache;
use Spatie\LaravelData\Support\DataClass;

/**
 * The Data structure cache is only a speed-up, so an unreachable backend must read as a miss.
 *
 * A fresh application caches in the database, and `capell:install` builds Data objects
 * (package roles, manifests) before its migrations have created the `cache` table. Without
 * this, that first read aborts the install with "no such table: cache". Once the backend has
 * failed, the rest of the process skips it instead of repeating a query that cannot succeed.
 */
final class ResilientDataStructureCache extends DataStructureCache
{
    private bool $backendUnavailable = false;

    #[Override]
    public function getConfig(): ?CachedDataConfig
    {
        return $this->whenBackendAvailable(fn (): ?CachedDataConfig => parent::getConfig(), null);
    }

    #[Override]
    public function storeConfig(CachedDataConfig $config): void
    {
        $this->whenBackendAvailable(fn () => parent::storeConfig($config), null);
    }

    #[Override]
    public function getDataClass(string $className): ?DataClass
    {
        return $this->whenBackendAvailable(fn (): ?DataClass => parent::getDataClass($className), null);
    }

    #[Override]
    public function storeDataClass(DataClass $dataClass): void
    {
        $this->whenBackendAvailable(fn () => parent::storeDataClass($dataClass), null);
    }

    /**
     * @template TResult
     * @template TFallback
     *
     * @param  callable(): TResult  $callback
     * @param  TFallback  $fallback
     * @return TResult|TFallback
     */
    private function whenBackendAvailable(callable $callback, mixed $fallback): mixed
    {
        if ($this->backendUnavailable) {
            return $fallback;
        }

        try {
            return $callback();
        } catch (QueryException) {
            $this->backendUnavailable = true;

            return $fallback;
        }
    }
}

<?php

declare(strict_types=1);

namespace Capell\Core\Data\Cache;

use Spatie\LaravelData\Data;

final class CacheRuntimeDiagnosticsData extends Data
{
    /**
     * @param  list<string>  $sampledKeyHashes
     */
    public function __construct(
        public readonly bool $enabled,
        public readonly bool $backendReachable,
        public readonly string $store,
        public readonly string $driver,
        public readonly int $hitCount,
        public readonly int $missCount,
        public readonly int $fillCount,
        public readonly int $backendFailureCount,
        public readonly array $sampledKeyHashes,
    ) {}
}

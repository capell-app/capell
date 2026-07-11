<?php

declare(strict_types=1);

namespace Capell\Frontend\Data;

use Spatie\LaravelData\Data;

class PublicRenderPerformanceReportData extends Data
{
    /**
     * @param  array<string, bool>  $runtimeModules
     * @param  array<string, int>  $assetCounts
     * @param  array<string, int>  $byteCounts
     * @param  array<int, string>  $surrogateKeys
     * @param  array<int, array<string, mixed>>  $assetReasons
     */
    public function __construct(
        public readonly string $renderingStrategy,
        public readonly array $runtimeModules,
        public readonly array $assetCounts,
        public readonly array $byteCounts,
        public readonly array $surrogateKeys,
        public readonly array $assetReasons,
        public readonly ?string $renderDataCacheKey = null,
        public readonly ?string $layoutGraphKey = null,
        public readonly ?int $lastRenderMilliseconds = null,
        public readonly ?string $routeType = null,
        public readonly ?bool $cacheHit = null,
    ) {}
}

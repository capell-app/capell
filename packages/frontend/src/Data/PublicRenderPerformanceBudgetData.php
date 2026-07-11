<?php

declare(strict_types=1);

namespace Capell\Frontend\Data;

use Spatie\LaravelData\Data;

class PublicRenderPerformanceBudgetData extends Data
{
    public function __construct(
        public readonly string $routeType = 'content',
        public readonly bool $allowJavaScript = false,
        public readonly bool $allowLivewire = false,
        public readonly bool $expectCacheHit = false,
        public readonly int $maxInlineBytes = 256,
        public readonly int $maxCssAssets = 2,
        public readonly int $maxJsBytes = 0,
        public readonly int $maxGzipJsBytes = 0,
        public readonly int $maxCssBytes = 1024,
        public readonly int $maxGzipCssBytes = 1024,
        public readonly int $maxCriticalCssBytes = 2048,
        public readonly int $maxMediaPreloads = 1,
    ) {}

    public static function forRouteType(string $routeType): self
    {
        return match ($routeType) {
            'homepage' => new self(routeType: 'homepage', maxInlineBytes: 512, maxCssAssets: 2, maxCssBytes: 2048, maxMediaPreloads: 2),
            'contact' => new self(routeType: 'contact', allowJavaScript: true, allowLivewire: true, maxInlineBytes: 512, maxCssAssets: 2, maxJsBytes: 4096, maxCssBytes: 2048),
            'resource-listing' => new self(routeType: 'resource-listing', maxInlineBytes: 512, maxCssAssets: 2, maxCssBytes: 2048, maxMediaPreloads: 2),
            'error' => new self(routeType: 'error', expectCacheHit: false, maxInlineBytes: 128, maxCssAssets: 1, maxCssBytes: 1024, maxMediaPreloads: 0),
            default => new self(routeType: $routeType),
        };
    }
}

<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Fragments;

final readonly class DeferredFragmentPlaceholderData
{
    public function __construct(
        public string $cacheKey,
        public string $url,
        public string $strategy,
        public ?string $minHeight,
        public string $variant = 'band',
    ) {}

    public function minHeightStyle(): string
    {
        return $this->minHeight === null ? '' : sprintf(' style="min-height: %s"', e($this->minHeight));
    }
}

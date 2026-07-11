<?php

declare(strict_types=1);

namespace Capell\Marketplace\Data;

use Spatie\LaravelData\Data;

final class MarketplaceCataloguePageData extends Data
{
    /** @param array<int, mixed> $extensions */
    public function __construct(
        public readonly array $extensions,
        public readonly int $total,
        public readonly int $currentPage,
        public readonly int $perPage,
        public readonly ?string $nextPageUrl = null,
        public readonly bool $stale = false,
    ) {}
}

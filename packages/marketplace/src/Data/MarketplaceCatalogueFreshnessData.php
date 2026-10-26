<?php

declare(strict_types=1);

namespace Capell\Marketplace\Data;

use Spatie\LaravelData\Data;

final class MarketplaceCatalogueFreshnessData extends Data
{
    public function __construct(
        public readonly bool $stale = false,
        public readonly ?string $observedAt = null,
        public readonly bool $refreshFailed = false,
    ) {}
}

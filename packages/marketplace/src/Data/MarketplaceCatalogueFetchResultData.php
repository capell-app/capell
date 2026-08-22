<?php

declare(strict_types=1);

namespace Capell\Marketplace\Data;

use Spatie\LaravelData\Data;

final class MarketplaceCatalogueFetchResultData extends Data
{
    public function __construct(
        public readonly MarketplaceCataloguePageData $page,
        public readonly ?string $unavailableReason = null,
    ) {}

    public function isUnavailable(): bool
    {
        return $this->unavailableReason !== null;
    }
}

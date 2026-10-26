<?php

declare(strict_types=1);

namespace Capell\Marketplace\Data;

use Capell\Marketplace\Enums\MarketplaceCatalogueFailure;
use Spatie\LaravelData\Data;

final class MarketplaceCatalogueFetchResultData extends Data
{
    public readonly ?string $unavailableReason;

    public function __construct(
        public readonly MarketplaceCataloguePageData $page,
        public readonly ?MarketplaceCatalogueFailure $failure = null,
    ) {
        $this->unavailableReason = $failure?->description();
    }

    public function isUnavailable(): bool
    {
        return $this->failure instanceof MarketplaceCatalogueFailure;
    }
}

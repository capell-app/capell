<?php

declare(strict_types=1);

namespace Capell\Marketplace\Data;

use Spatie\LaravelData\Data;

final class MarketplaceCatalogueRecordData extends Data
{
    /**
     * @param  array<string, string>  $compatibilityDetails
     * @param  list<string>  $imageUrls
     */
    public function __construct(
        public readonly ExtensionListingData $listing,
        public readonly MarketplaceCatalogueLocalStateData $localState,
        public readonly array $compatibilityDetails,
        public readonly bool $isCompatible,
        public readonly MarketplaceInstallEligibilityData $eligibility,
        public readonly ?string $documentationUrl,
        public readonly ?string $purchaseUrl,
        public readonly ?string $imageUrl,
        public readonly array $imageUrls,
    ) {}
}

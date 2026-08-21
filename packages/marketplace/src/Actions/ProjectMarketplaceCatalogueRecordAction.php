<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Core\Support\Marketplace\MarketplaceAssetUrl;
use Capell\Marketplace\Data\ExtensionListingData;
use Capell\Marketplace\Data\MarketplaceCatalogueLocalStateData;
use Capell\Marketplace\Data\MarketplaceCatalogueRecordData;
use Capell\Marketplace\Models\MarketplaceInstance;
use Capell\Marketplace\Services\VersionCompatibilityChecker;
use Capell\Marketplace\Support\MarketplaceTrustedUrlPolicy;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class ProjectMarketplaceCatalogueRecordAction
{
    use AsFake;
    use AsObject;

    public function __construct(
        private readonly VersionCompatibilityChecker $compatibility,
        private readonly MarketplaceTrustedUrlPolicy $trustedUrls,
    ) {}

    public function handle(
        ExtensionListingData $listing,
        MarketplaceCatalogueLocalStateData $localState,
        ?MarketplaceInstance $instance,
    ): MarketplaceCatalogueRecordData {
        $compatibilityDetails = $this->compatibility->compatibilityDetails($listing);

        return new MarketplaceCatalogueRecordData(
            listing: $listing,
            localState: $localState,
            compatibilityDetails: $compatibilityDetails,
            isCompatible: ! in_array('incompatible', $compatibilityDetails, true),
            eligibility: ResolveMarketplaceInstallEligibilityAction::run(
                listing: $listing,
                instance: $instance,
                action: 'install',
                remoteEligibility: $listing->installEligibilityPolicy,
            ),
            documentationUrl: $this->trustedUrls->trusted($listing->documentationUrl),
            purchaseUrl: $this->trustedUrls->trusted($listing->purchaseUrl),
            imageUrl: MarketplaceAssetUrl::toUrl($listing->imageUrl),
            imageUrls: array_values(array_filter(array_map(
                MarketplaceAssetUrl::toUrl(...),
                $listing->imageUrls,
            ), static fn (?string $url): bool => is_string($url) && $url !== '')),
        );
    }
}

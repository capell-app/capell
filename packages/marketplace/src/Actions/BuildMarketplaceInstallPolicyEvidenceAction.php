<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Core\Support\Json\JsonCodec;
use Capell\Marketplace\Data\ExtensionListingData;
use Capell\Marketplace\Data\MarketplaceInstallPolicyEvidenceData;
use Carbon\CarbonImmutable;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class BuildMarketplaceInstallPolicyEvidenceAction
{
    use AsFake;
    use AsObject;

    /** @param array<string, string> $dependencyMaturity */
    public function handle(
        ExtensionListingData $listing,
        array $dependencyMaturity = [],
        bool $entitlementAllowed = true,
        bool $compatibilityAllowed = true,
        bool $consentAllowed = true,
        ?string $reason = null,
        ?string $blockingDependency = null,
        ?CarbonImmutable $fetchedAt = null,
    ): MarketplaceInstallPolicyEvidenceData {
        ksort($dependencyMaturity);

        return new MarketplaceInstallPolicyEvidenceData(
            listingFingerprint: hash('sha256', JsonCodec::encode($listing->toArray())),
            listingFetchedAt: $fetchedAt ?? CarbonImmutable::now(),
            selectedMaturity: $listing->maturity,
            dependencyMaturity: $dependencyMaturity,
            entitlementAllowed: $entitlementAllowed,
            compatibilityAllowed: $compatibilityAllowed,
            consentAllowed: $consentAllowed,
            reason: $reason,
            blockingDependency: $blockingDependency,
        );
    }
}

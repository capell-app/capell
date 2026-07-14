<?php

declare(strict_types=1);

namespace Capell\Marketplace\Data;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

final class MarketplaceInstallPolicyEvidenceData extends Data
{
    /** @param array<string, string> $dependencyMaturity */
    public function __construct(
        public readonly string $listingFingerprint,
        public readonly CarbonImmutable $listingFetchedAt,
        public readonly string $selectedMaturity,
        public readonly array $dependencyMaturity,
        public readonly bool $entitlementAllowed,
        public readonly bool $compatibilityAllowed,
        public readonly bool $consentAllowed,
        public readonly ?string $reason = null,
        public readonly ?string $blockingDependency = null,
    ) {}

    /** @return list<string> */
    public function betaDependencies(): array
    {
        return array_keys(array_filter(
            $this->dependencyMaturity,
            static fn (string $maturity): bool => $maturity === 'beta',
        ));
    }
}

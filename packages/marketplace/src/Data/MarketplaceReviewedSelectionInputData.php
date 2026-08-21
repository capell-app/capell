<?php

declare(strict_types=1);

namespace Capell\Marketplace\Data;

use Spatie\LaravelData\Data;

final class MarketplaceReviewedSelectionInputData extends Data
{
    /** @param array<string, mixed> $selectedInstallOptions */
    public function __construct(
        public readonly MarketplaceSelectionReviewData $selection,
        public readonly MarketplaceEnvironmentReadinessData $readiness,
        public readonly bool $confirmed,
        public readonly bool $betaAcknowledged,
        public readonly ?string $licenseKey,
        public readonly bool $activateThemesAfterInstall,
        public readonly array $selectedInstallOptions,
        public readonly MarketplaceInstallActorData $actor,
        public readonly string $returnUrl,
    ) {}
}

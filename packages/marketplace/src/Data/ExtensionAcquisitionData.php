<?php

declare(strict_types=1);

namespace Capell\Marketplace\Data;

use Spatie\LaravelData\Data;

final class ExtensionAcquisitionData extends Data
{
    /**
     * @param  array<string, mixed>|null  $composerAuth
     * @param  array<string, mixed>  $signedActivation
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $composerName,
        public string $versionConstraint,
        public string $composerCommand,
        public ?string $repositoryUrl,
        public ?string $purchaseUrl,
        public bool $requiresDeployment,
        public ?array $composerAuth = null,
        public array $signedActivation = [],
        public array $metadata = [],
        public ?MarketplaceInstallEligibilityData $authorizationEligibilityPolicy = null,
    ) {}
}

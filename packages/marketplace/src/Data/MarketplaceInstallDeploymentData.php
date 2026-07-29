<?php

declare(strict_types=1);

namespace Capell\Marketplace\Data;

use Spatie\LaravelData\Data;

final class MarketplaceInstallDeploymentData extends Data
{
    /** @param array<string, mixed> $deployment */
    public function __construct(
        public readonly array $deployment,
    ) {}
}

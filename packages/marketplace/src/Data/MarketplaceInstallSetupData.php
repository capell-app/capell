<?php

declare(strict_types=1);

namespace Capell\Marketplace\Data;

use Spatie\LaravelData\Data;

final class MarketplaceInstallSetupData extends Data
{
    public function __construct(
        public readonly ?string $installedUrl,
        public readonly ?string $documentationUrl,
        public readonly bool $hasEditor,
        public readonly bool $runtimeRefreshRequired,
        public readonly ?string $runtimeRefreshInstructions = null,
    ) {}
}

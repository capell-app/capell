<?php

declare(strict_types=1);

namespace Capell\Marketplace\Data;

use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Spatie\LaravelData\Data;

final class MarketplaceCatalogueOperationData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly MarketplaceInstallIntentStatus $status,
    ) {}
}

<?php

declare(strict_types=1);

namespace Capell\Core\Data\Marketplace;

use Spatie\LaravelData\Data;

final class MarketplaceIssuanceResultData extends Data
{
    public function __construct(
        public readonly bool $catalogueAccepted,
        public readonly bool $catalogueRejected,
        public readonly int $receiptsAccepted,
        public readonly int $receiptsRejected,
        public readonly int $revocationsApplied,
    ) {}
}

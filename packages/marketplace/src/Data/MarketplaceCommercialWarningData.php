<?php

declare(strict_types=1);

namespace Capell\Marketplace\Data;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

final class MarketplaceCommercialWarningData extends Data
{
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $status,
        public readonly string $severity,
        public readonly ?CarbonImmutable $accessEndsAt,
    ) {}
}

<?php

declare(strict_types=1);

namespace Capell\Marketplace\Data;

use Capell\Marketplace\Enums\MarketplaceConnectionFailure;
use Spatie\LaravelData\Data;

final class MarketplaceConnectionStartResultData extends Data
{
    public function __construct(
        public readonly ?string $approvalUrl = null,
        public readonly ?MarketplaceConnectionFailure $failure = null,
    ) {}
}

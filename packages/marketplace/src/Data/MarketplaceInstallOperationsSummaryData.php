<?php

declare(strict_types=1);

namespace Capell\Marketplace\Data;

use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Illuminate\Database\Eloquent\Collection;

final readonly class MarketplaceInstallOperationsSummaryData
{
    /**
     * @param  Collection<int, MarketplaceInstallAttempt>  $operations
     */
    public function __construct(
        public Collection $operations,
        public int $operationsCount,
        public int $activeCount,
        public int $attentionCount,
    ) {}
}

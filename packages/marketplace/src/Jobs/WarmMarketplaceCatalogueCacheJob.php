<?php

declare(strict_types=1);

namespace Capell\Marketplace\Jobs;

use Capell\Marketplace\Actions\WarmMarketplaceCatalogueCacheAction;
use Capell\Marketplace\Data\MarketplaceCatalogueQueryData;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class WarmMarketplaceCatalogueCacheJob implements ShouldQueue
{
    use Queueable;

    /** @param array<string, mixed> $queryPayload */
    public function __construct(private readonly array $queryPayload = []) {}

    public function handle(): void
    {
        WarmMarketplaceCatalogueCacheAction::run(
            $this->queryPayload === []
                ? new MarketplaceCatalogueQueryData
                : MarketplaceCatalogueQueryData::fromPayload($this->queryPayload),
        );
    }
}

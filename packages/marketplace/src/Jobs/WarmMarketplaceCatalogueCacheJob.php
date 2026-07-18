<?php

declare(strict_types=1);

namespace Capell\Marketplace\Jobs;

use Capell\Core\Support\Json\JsonCodec;
use Capell\Marketplace\Actions\WarmMarketplaceCatalogueCacheAction;
use Capell\Marketplace\Data\MarketplaceCatalogueQueryData;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class WarmMarketplaceCatalogueCacheJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 60;

    /** @param array<string, mixed> $queryPayload */
    public function __construct(private readonly array $queryPayload = []) {}

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function uniqueId(): string
    {
        $query = $this->queryPayload === []
            ? new MarketplaceCatalogueQueryData
            : MarketplaceCatalogueQueryData::fromPayload($this->queryPayload);

        return hash('xxh3', JsonCodec::encode($query->toPayload()));
    }

    public function handle(): void
    {
        WarmMarketplaceCatalogueCacheAction::run(
            $this->queryPayload === []
                ? new MarketplaceCatalogueQueryData
                : MarketplaceCatalogueQueryData::fromPayload($this->queryPayload),
        );
    }
}

<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Data\MarketplaceCatalogueQueryData;
use Capell\Marketplace\Jobs\WarmMarketplaceCatalogueCacheJob;
use Capell\Marketplace\Services\MarketplaceClient;
use Illuminate\Support\Facades\Cache;
use Lorisleiva\Actions\Concerns\AsAction;

final class QueueMarketplaceCatalogueWarmAction
{
    use AsAction;

    public function __construct(private readonly MarketplaceClient $marketplace) {}

    public function handle(?MarketplaceCatalogueQueryData $query = null): bool
    {
        $query ??= new MarketplaceCatalogueQueryData;
        $payload = $query->toPayload();
        $cachePayload = $this->marketplace->catalogueCachePayload($query);
        $cacheKey = 'capell-marketplace.marketplace.catalogue-warm.' . hash('xxh3', json_encode($cachePayload, JSON_THROW_ON_ERROR));
        $throttleSeconds = config('capell-marketplace.marketplace.warm_throttle_seconds', 60);

        if (! Cache::add($cacheKey, true, $throttleSeconds)) {
            return false;
        }

        WarmMarketplaceCatalogueCacheJob::dispatchAfterResponse($payload);

        return true;
    }
}

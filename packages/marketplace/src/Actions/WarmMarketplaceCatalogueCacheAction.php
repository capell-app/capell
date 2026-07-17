<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Data\MarketplaceCatalogueQueryData;
use Capell\Marketplace\Services\MarketplaceClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

final class WarmMarketplaceCatalogueCacheAction
{
    use AsFake;
    use AsObject;

    public function __construct(private readonly MarketplaceClient $marketplace) {}

    public function handle(?MarketplaceCatalogueQueryData $query = null): void
    {
        try {
            $this->marketplace->listExtensionPage(
                query: $query ?? new MarketplaceCatalogueQueryData,
                forceRefresh: true,
            );
        } catch (Throwable $throwable) {
            $cacheKey = 'capell-marketplace.marketplace.catalogue-warm-failed-log.' . hash('xxh3', $throwable::class . '|' . $throwable->getMessage());
            $throttleSeconds = config('capell-marketplace.marketplace.warm_failure_log_throttle_seconds', 300);

            if (! Cache::add($cacheKey, true, $throttleSeconds)) {
                return;
            }

            Log::warning('capell-marketplace: marketplace catalogue warm failed', [
                'error' => $throwable->getMessage(),
            ]);
        }
    }
}

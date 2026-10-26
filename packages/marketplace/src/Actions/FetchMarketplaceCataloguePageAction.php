<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Data\MarketplaceCatalogueFetchResultData;
use Capell\Marketplace\Data\MarketplaceCataloguePageData;
use Capell\Marketplace\Data\MarketplaceCatalogueQueryData;
use Capell\Marketplace\Enums\MarketplaceCatalogueFailure;
use Capell\Marketplace\Services\MarketplaceClient;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

final class FetchMarketplaceCataloguePageAction
{
    use AsFake;
    use AsObject;

    public function __construct(private readonly MarketplaceClient $marketplace) {}

    public function handle(
        MarketplaceCatalogueQueryData $query,
        bool $allowStale = false,
    ): MarketplaceCatalogueFetchResultData {
        try {
            $page = $this->marketplace->listExtensionPage($query, allowStale: $allowStale);

            if ($page->stale) {
                QueueMarketplaceCatalogueWarmAction::run($query);
            }

            return new MarketplaceCatalogueFetchResultData($page);
        } catch (Throwable $throwable) {
            Log::warning('capell-marketplace: marketplace browse failed', [
                'exception_class' => $throwable::class,
                'failure' => MarketplaceCatalogueFailure::fromThrowable($throwable)->value,
            ]);

            return new MarketplaceCatalogueFetchResultData(
                page: new MarketplaceCataloguePageData(
                    extensions: [],
                    total: 0,
                    currentPage: $query->page,
                    perPage: $query->perPage,
                ),
                failure: MarketplaceCatalogueFailure::fromThrowable($throwable),
            );
        }
    }
}

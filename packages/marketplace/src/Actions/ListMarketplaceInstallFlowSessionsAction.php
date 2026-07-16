<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Enums\MarketplaceInstallFlowSessionStatus;
use Capell\Marketplace\Models\MarketplaceInstallFlowSession;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class ListMarketplaceInstallFlowSessionsAction
{
    use AsFake;
    use AsObject;

    /**
     * @return Collection<int, MarketplaceInstallFlowSession>
     */
    public function handle(?int $limit = 10): Collection
    {
        $query = $this->baseQuery();

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->latest()->get();
    }

    public function count(): int
    {
        return $this->baseQuery()->count();
    }

    /** @return Builder<MarketplaceInstallFlowSession> */
    private function baseQuery(): Builder
    {
        return MarketplaceInstallFlowSession::query()
            ->whereIn('status', [
                MarketplaceInstallFlowSessionStatus::Redirected->value,
                MarketplaceInstallFlowSessionStatus::Authorizing->value,
                MarketplaceInstallFlowSessionStatus::Returned->value,
                MarketplaceInstallFlowSessionStatus::Queued->value,
                MarketplaceInstallFlowSessionStatus::Failed->value,
            ]);
    }
}

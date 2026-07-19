<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Illuminate\Database\Eloquent\Collection;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class FindStuckMarketplaceInstallOperationsAction
{
    use AsFake;
    use AsObject;

    /** @return Collection<int, MarketplaceInstallAttempt> */
    public function handle(int $staleAfterMinutes = 15): Collection
    {
        $staleBefore = now()->subMinutes(max(1, $staleAfterMinutes));

        return MarketplaceInstallAttempt::query()
            ->whereIn('status', [
                MarketplaceInstallIntentStatus::Running->value,
                MarketplaceInstallIntentStatus::CancelRequested->value,
            ])
            ->where(function ($query) use ($staleBefore): void {
                $query
                    ->where('heartbeat_at', '<', $staleBefore)
                    ->orWhere(function ($query) use ($staleBefore): void {
                        $query
                            ->whereNull('heartbeat_at')
                            ->where('started_at', '<', $staleBefore);
                    });
            })
            ->oldest('started_at')
            ->get();
    }
}

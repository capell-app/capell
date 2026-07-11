<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Illuminate\Database\Eloquent\Builder;
use Lorisleiva\Actions\Concerns\AsAction;

final class ClearMarketplaceInstallOperationsAction
{
    use AsAction;

    public function handle(): int
    {
        $resolved = 0;

        $this->clearableQuery()
            ->get()
            ->each(function (MarketplaceInstallAttempt $attempt) use (&$resolved): void {
                if (ResolveMarketplaceInstallOperationAction::run($attempt)) {
                    $resolved++;
                }
            });

        return $resolved;
    }

    public function count(): int
    {
        return $this->clearableQuery()->count();
    }

    /** @return Builder<MarketplaceInstallAttempt> */
    private function clearableQuery(): Builder
    {
        return MarketplaceInstallAttempt::query()
            ->whereNull('resolved_at')
            ->whereNotIn('status', [
                MarketplaceInstallIntentStatus::Queued->value,
                MarketplaceInstallIntentStatus::Running->value,
                MarketplaceInstallIntentStatus::CancelRequested->value,
            ])
            ->where(function (Builder $query): void {
                $query
                    ->whereIn('status', [
                        MarketplaceInstallIntentStatus::Failed->value,
                        MarketplaceInstallIntentStatus::TimedOut->value,
                        MarketplaceInstallIntentStatus::Cancelled->value,
                    ])
                    ->orWhereIn('deployment->status', ['failed', 'unavailable']);
            });
    }
}

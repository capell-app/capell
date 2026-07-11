<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Lorisleiva\Actions\Concerns\AsAction;

final class ListMarketplaceInstallOperationsAction
{
    use AsAction;

    /**
     * @return Collection<int, MarketplaceInstallAttempt>
     */
    public function handle(bool $attentionOnly = false, ?int $limit = 10): Collection
    {
        if (! $attentionOnly && $limit === 10) {
            return BuildMarketplaceInstallOperationsSummaryAction::run()->operations;
        }

        $query = $this->baseQuery($attentionOnly);

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->latest()->get();
    }

    public function count(bool $attentionOnly = false): int
    {
        $summary = BuildMarketplaceInstallOperationsSummaryAction::run();

        if (! $attentionOnly) {
            return $summary->operationsCount;
        }

        return $summary->attentionCount;
    }

    public function legacyCount(bool $attentionOnly = false): int
    {
        return $this->baseQuery($attentionOnly)->count();
    }

    public function activeCount(): int
    {
        return BuildMarketplaceInstallOperationsSummaryAction::run()->activeCount;
    }

    public function legacyActiveCount(): int
    {
        return MarketplaceInstallAttempt::query()
            ->whereIn('status', [
                MarketplaceInstallIntentStatus::Queued->value,
                MarketplaceInstallIntentStatus::Running->value,
                MarketplaceInstallIntentStatus::CancelRequested->value,
            ])
            ->count();
    }

    /** @return Builder<MarketplaceInstallAttempt> */
    private function baseQuery(bool $attentionOnly = false): Builder
    {
        $statuses = $attentionOnly
            ? [
                MarketplaceInstallIntentStatus::Failed,
                MarketplaceInstallIntentStatus::TimedOut,
                MarketplaceInstallIntentStatus::CancelRequested,
                MarketplaceInstallIntentStatus::Cancelled,
            ]
            : [
                MarketplaceInstallIntentStatus::Queued,
                MarketplaceInstallIntentStatus::Running,
                MarketplaceInstallIntentStatus::CancelRequested,
                MarketplaceInstallIntentStatus::Failed,
                MarketplaceInstallIntentStatus::TimedOut,
                MarketplaceInstallIntentStatus::Cancelled,
            ];

        return MarketplaceInstallAttempt::query()
            ->whereNull('resolved_at')
            ->where(function (Builder $query) use ($statuses): void {
                $query
                    ->whereIn('status', array_map(
                        static fn (MarketplaceInstallIntentStatus $status): string => $status->value,
                        $statuses,
                    ))
                    ->orWhereIn('deployment->status', ['failed', 'unavailable']);
            });
    }
}

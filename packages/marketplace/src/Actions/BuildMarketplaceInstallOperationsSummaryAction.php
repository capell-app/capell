<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Data\MarketplaceInstallOperationsSummaryData;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Illuminate\Database\Eloquent\Builder;
use Lorisleiva\Actions\Concerns\AsAction;

final class BuildMarketplaceInstallOperationsSummaryAction
{
    use AsAction;

    private ?MarketplaceInstallOperationsSummaryData $summary = null;

    public function handle(?int $limit = 10): MarketplaceInstallOperationsSummaryData
    {
        if ($this->summary instanceof MarketplaceInstallOperationsSummaryData) {
            return $this->summary;
        }

        $operations = $this->baseQuery()
            ->latest()
            ->when($limit !== null, fn (Builder $query): Builder => $query->limit($limit))
            ->get();

        return $this->summary = new MarketplaceInstallOperationsSummaryData(
            operations: $operations,
            operationsCount: $this->baseQuery()->count(),
            activeCount: $this->activeQuery()->count(),
            attentionCount: $this->attentionQuery()->count(),
        );
    }

    public function forget(): void
    {
        $this->summary = null;
    }

    /** @return Builder<MarketplaceInstallAttempt> */
    private function baseQuery(): Builder
    {
        return MarketplaceInstallAttempt::query()
            ->whereNull('resolved_at')
            ->where(function (Builder $query): void {
                $query
                    ->whereIn('status', $this->operationStatusValues())
                    ->orWhereIn('deployment->status', ['failed', 'unavailable']);
            });
    }

    /** @return Builder<MarketplaceInstallAttempt> */
    private function activeQuery(): Builder
    {
        return MarketplaceInstallAttempt::query()
            ->whereNull('resolved_at')
            ->whereIn('status', [
                MarketplaceInstallIntentStatus::Queued->value,
                MarketplaceInstallIntentStatus::Running->value,
                MarketplaceInstallIntentStatus::CancelRequested->value,
            ]);
    }

    /** @return Builder<MarketplaceInstallAttempt> */
    private function attentionQuery(): Builder
    {
        return MarketplaceInstallAttempt::query()
            ->whereNull('resolved_at')
            ->where(function (Builder $query): void {
                $query
                    ->whereIn('status', [
                        MarketplaceInstallIntentStatus::Failed->value,
                        MarketplaceInstallIntentStatus::TimedOut->value,
                        MarketplaceInstallIntentStatus::CancelRequested->value,
                        MarketplaceInstallIntentStatus::Cancelled->value,
                    ])
                    ->orWhereIn('deployment->status', ['failed', 'unavailable']);
            });
    }

    /** @return list<string> */
    private function operationStatusValues(): array
    {
        return [
            MarketplaceInstallIntentStatus::Queued->value,
            MarketplaceInstallIntentStatus::Running->value,
            MarketplaceInstallIntentStatus::CancelRequested->value,
            MarketplaceInstallIntentStatus::Failed->value,
            MarketplaceInstallIntentStatus::TimedOut->value,
            MarketplaceInstallIntentStatus::Cancelled->value,
        ];
    }
}

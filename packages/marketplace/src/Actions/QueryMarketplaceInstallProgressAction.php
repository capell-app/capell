<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Data\MarketplaceInstallProgressData;
use Capell\Marketplace\Data\MarketplaceInstallProgressQueryData;
use Capell\Marketplace\Data\MarketplaceInstallProgressResultData;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class QueryMarketplaceInstallProgressAction
{
    use AsFake;
    use AsObject;

    public function handle(MarketplaceInstallProgressQueryData $query): MarketplaceInstallProgressResultData
    {
        if ($query->attemptIds === [] && $query->composerNames === []) {
            return new MarketplaceInstallProgressResultData([]);
        }

        /** @var Builder<MarketplaceInstallAttempt> $attemptsQuery */
        $attemptsQuery = MarketplaceInstallAttempt::query();

        if ($query->attemptIds !== []) {
            $attemptsQuery
                ->whereKey($query->attemptIds)
                ->orderBy('id');
        } else {
            $attemptsQuery
                ->whereIn('composer_name', $query->composerNames)
                ->whereIn('status', [
                    MarketplaceInstallIntentStatus::Queued->value,
                    MarketplaceInstallIntentStatus::Running->value,
                    MarketplaceInstallIntentStatus::CancelRequested->value,
                ])
                ->latest();
        }

        /** @var Collection<int, MarketplaceInstallAttempt> $attempts */
        $attempts = $attemptsQuery->get();

        if ($query->attemptIds === []) {
            $attempts = $attempts->unique('composer_name')->values();
        }

        return new MarketplaceInstallProgressResultData($attempts
            ->map(static fn (MarketplaceInstallAttempt $attempt): MarketplaceInstallProgressData => new MarketplaceInstallProgressData(
                id: (int) $attempt->getKey(),
                name: $attempt->extension_name,
                composerName: $attempt->composer_name,
                status: $attempt->status,
                stage: $attempt->current_stage,
                progressCurrent: max(0, $attempt->progress_current ?? 0),
                progressTotal: max(1, $attempt->progress_total ?? 5),
                failureReason: $attempt->failure_reason,
            ))
            ->values()
            ->all());
    }
}

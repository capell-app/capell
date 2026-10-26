<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Data\MarketplaceInstallProgressData;
use Capell\Marketplace\Data\MarketplaceInstallProgressQueryData;
use Capell\Marketplace\Data\MarketplaceInstallProgressResultData;
use Capell\Marketplace\Enums\MarketplaceInstallAttemptEventLevel;
use Capell\Marketplace\Enums\MarketplaceInstallFailureStage;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Enums\MarketplaceRuntimePropagationStatus;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Capell\Marketplace\Models\MarketplaceInstallAttemptEvent;
use Capell\Marketplace\Support\ArtisanMarketplaceRuntimeRefresher;
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
        $attemptsQuery = MarketplaceInstallAttempt::query()->withExists([
            'events as runtime_refresh_required' => $this->runtimeRefreshEvents(...),
        ]);

        if ($query->attemptIds !== []) {
            $attemptsQuery
                ->whereKey($query->attemptIds)
                ->orderBy('id');
        } else {
            $attemptsQuery
                ->whereIn('composer_name', $query->composerNames)
                ->where(static function (Builder $attempts): void {
                    $attempts->whereIn('status', [
                        MarketplaceInstallIntentStatus::Queued->value,
                        MarketplaceInstallIntentStatus::Running->value,
                        MarketplaceInstallIntentStatus::CancelRequested->value,
                    ])->orWhere(static function (Builder $pendingPropagation): void {
                        $pendingPropagation->where('status', MarketplaceInstallIntentStatus::Succeeded->value)
                            ->where('context->runtime_propagation', MarketplaceRuntimePropagationStatus::Pending->value);
                    });
                })
                ->latest();
        }

        /** @var Collection<int, MarketplaceInstallAttempt> $attempts */
        $attempts = $attemptsQuery->get();

        if ($query->attemptIds === []) {
            $attempts = $attempts->unique('composer_name')->values();
        }

        foreach ($attempts as $attempt) {
            RecoverMarketplaceRuntimePropagationAction::run($attempt);
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
                runtimeRefreshRequired: (bool) $attempt->getAttribute('runtime_refresh_required')
                    || ! in_array($attempt->context['runtime_propagation'] ?? null, [MarketplaceRuntimePropagationStatus::Ready->value, MarketplaceRuntimePropagationStatus::Pending->value], true),
                runtimePropagationStatus: MarketplaceRuntimePropagationStatus::tryFrom((string) ($attempt->context['runtime_propagation'] ?? ''))
                    ?? MarketplaceRuntimePropagationStatus::RecoveryRequired,
            ))
            ->values()
            ->all());
    }

    /**
     * @param  Builder<MarketplaceInstallAttemptEvent>  $events
     * @return Builder<MarketplaceInstallAttemptEvent>
     */
    private function runtimeRefreshEvents(Builder $events): Builder
    {
        return $events
            ->where('level', MarketplaceInstallAttemptEventLevel::Warning->value)
            ->where('stage', MarketplaceInstallFailureStage::Notification->value)
            ->where('context->command', ArtisanMarketplaceRuntimeRefresher::COMMAND);
    }
}

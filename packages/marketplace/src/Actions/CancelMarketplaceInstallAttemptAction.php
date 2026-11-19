<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Data\MarketplaceInstallAttemptTransitionData;
use Capell\Marketplace\Enums\MarketplaceInstallAttemptEventLevel;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class CancelMarketplaceInstallAttemptAction
{
    use AsFake;
    use AsObject;

    public function handle(MarketplaceInstallAttempt $attempt): MarketplaceInstallAttempt
    {
        return DB::transaction(function () use ($attempt): MarketplaceInstallAttempt {
            $lockedAttempt = MarketplaceInstallAttempt::query()
                ->whereKey((int) $attempt->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // The published pull request or commit is outside this site's
            // control, so the operator is told it will still add the package
            // when it deploys unless they close or revert it.
            if ($lockedAttempt->status === MarketplaceInstallIntentStatus::AwaitingDeployment) {
                return TransitionMarketplaceInstallAttemptAction::run(
                    $lockedAttempt,
                    new MarketplaceInstallAttemptTransitionData(
                        toStatus: MarketplaceInstallIntentStatus::Cancelled,
                        timelineMessage: (string) __('capell-marketplace::marketplace.operations.timeline_cancelled_awaiting_deployment'),
                        timelineLevel: MarketplaceInstallAttemptEventLevel::Warning,
                    ),
                );
            }

            if ($lockedAttempt->status === MarketplaceInstallIntentStatus::Queued) {
                return TransitionMarketplaceInstallAttemptAction::run(
                    $lockedAttempt,
                    new MarketplaceInstallAttemptTransitionData(
                        toStatus: MarketplaceInstallIntentStatus::Cancelled,
                    ),
                );
            }

            if ($lockedAttempt->status === MarketplaceInstallIntentStatus::Running) {
                return TransitionMarketplaceInstallAttemptAction::run(
                    $lockedAttempt,
                    new MarketplaceInstallAttemptTransitionData(
                        toStatus: MarketplaceInstallIntentStatus::CancelRequested,
                    ),
                );
            }

            return $lockedAttempt;
        });
    }
}

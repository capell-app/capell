<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Data\MarketplaceInstallAttemptTransitionData;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class ClaimMarketplaceInstallAttemptAction
{
    use AsFake;
    use AsObject;

    public function handle(
        MarketplaceInstallAttempt $attempt,
        int $attemptCount,
        int $progressTotal,
    ): ?MarketplaceInstallAttempt {
        return DB::transaction(function () use ($attempt, $attemptCount, $progressTotal): ?MarketplaceInstallAttempt {
            $lockedAttempt = MarketplaceInstallAttempt::query()
                ->whereKey((int) $attempt->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedAttempt->status === MarketplaceInstallIntentStatus::CancelRequested) {
                TransitionMarketplaceInstallAttemptAction::run(
                    $lockedAttempt,
                    new MarketplaceInstallAttemptTransitionData(
                        toStatus: MarketplaceInstallIntentStatus::Cancelled,
                    ),
                );

                return null;
            }

            if ($lockedAttempt->status !== MarketplaceInstallIntentStatus::Queued) {
                return null;
            }

            return TransitionMarketplaceInstallAttemptAction::run(
                $lockedAttempt,
                new MarketplaceInstallAttemptTransitionData(
                    toStatus: MarketplaceInstallIntentStatus::Running,
                    attemptCount: $attemptCount,
                    progressTotal: $progressTotal,
                ),
            );
        });
    }
}

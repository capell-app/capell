<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Data\MarketplaceInstallAttemptTransitionData;
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

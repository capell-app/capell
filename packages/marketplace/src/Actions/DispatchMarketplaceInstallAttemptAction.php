<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Jobs\RunMarketplaceInstallAttemptJob;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class DispatchMarketplaceInstallAttemptAction
{
    use AsFake;
    use AsObject;

    public function handle(
        MarketplaceInstallAttempt $attempt,
        string $queueConnection,
        string $queue,
    ): MarketplaceInstallAttempt {
        return DB::transaction(function () use ($attempt, $queueConnection, $queue): MarketplaceInstallAttempt {
            $lockedAttempt = MarketplaceInstallAttempt::query()
                ->whereKey((int) $attempt->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedAttempt->status !== MarketplaceInstallIntentStatus::Queued) {
                return $lockedAttempt;
            }

            dispatch(new RunMarketplaceInstallAttemptJob((int) $lockedAttempt->getKey()))
                ->onConnection($queueConnection)
                ->onQueue($queue);

            return $lockedAttempt;
        });
    }
}

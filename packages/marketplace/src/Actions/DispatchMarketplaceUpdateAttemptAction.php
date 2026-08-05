<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Jobs\RunMarketplaceUpdateAttemptJob;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class DispatchMarketplaceUpdateAttemptAction
{
    use AsFake;
    use AsObject;

    public function handle(
        MarketplaceInstallAttempt $attempt,
        string $queueConnection,
        string $queue,
    ): MarketplaceInstallAttempt {
        return DB::transaction(function () use ($attempt, $queueConnection, $queue): MarketplaceInstallAttempt {
            // Locked and re-read inside the transaction so a cancel taken
            // between creating the attempt and dispatching it wins, rather than
            // being overtaken by a job that was already on its way.
            $lockedAttempt = MarketplaceInstallAttempt::query()
                ->whereKey((int) $attempt->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedAttempt->status !== MarketplaceInstallIntentStatus::Queued) {
                return $lockedAttempt;
            }

            dispatch(new RunMarketplaceUpdateAttemptJob((int) $lockedAttempt->getKey()))
                ->onConnection($queueConnection)
                ->onQueue($queue);

            return $lockedAttempt;
        });
    }
}

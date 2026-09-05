<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Jobs\AbstractMarketplaceOperationJob;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsObject;

final class DispatchMarketplaceAttemptAction
{
    use AsObject;

    /** @param class-string<AbstractMarketplaceOperationJob> $jobClass */
    public function handle(
        MarketplaceInstallAttempt $attempt,
        string $queueConnection,
        string $queue,
        string $jobClass,
    ): MarketplaceInstallAttempt {
        return DB::transaction(function () use ($attempt, $queueConnection, $queue, $jobClass): MarketplaceInstallAttempt {
            $lockedAttempt = MarketplaceInstallAttempt::query()
                ->whereKey((int) $attempt->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedAttempt->status !== MarketplaceInstallIntentStatus::Queued) {
                return $lockedAttempt;
            }

            dispatch(new $jobClass((int) $lockedAttempt->getKey()))
                ->onConnection($queueConnection)
                ->onQueue($queue);

            return $lockedAttempt;
        });
    }
}

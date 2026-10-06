<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Jobs\AbstractMarketplaceOperationJob;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class DispatchMarketplaceAttemptAction
{
    use AsFake;
    use AsObject;

    /** @param class-string<AbstractMarketplaceOperationJob> $jobClass */
    public function handle(
        MarketplaceInstallAttempt $attempt,
        string $queueConnection,
        string $queue,
        string $jobClass,
        bool $afterResponse = false,
    ): MarketplaceInstallAttempt {
        return DB::transaction(function () use ($attempt, $queueConnection, $queue, $jobClass, $afterResponse): MarketplaceInstallAttempt {
            $lockedAttempt = MarketplaceInstallAttempt::query()
                ->whereKey((int) $attempt->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedAttempt->status !== MarketplaceInstallIntentStatus::Queued) {
                return $lockedAttempt;
            }

            $pendingDispatch = dispatch(new $jobClass((int) $lockedAttempt->getKey()))
                ->onConnection($queueConnection)
                ->onQueue($queue);

            if ($afterResponse) {
                $pendingDispatch->afterResponse();
            }

            return $lockedAttempt;
        });
    }
}

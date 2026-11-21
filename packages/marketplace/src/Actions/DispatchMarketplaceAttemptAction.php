<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Jobs\AbstractMarketplaceOperationJob;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Closure;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class DispatchMarketplaceAttemptAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  class-string<AbstractMarketplaceOperationJob>|(Closure(int): AbstractMarketplaceOperationJob)  $jobClass
     */
    public function handle(
        MarketplaceInstallAttempt $attempt,
        string $queueConnection,
        string $queue,
        string|Closure $jobClass,
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

            $id = (int) $lockedAttempt->getKey();
            $job = $jobClass instanceof Closure ? $jobClass($id) : new $jobClass($id);
            $pendingDispatch = dispatch($job)
                ->onConnection($queueConnection)
                ->onQueue($queue);

            if ($afterResponse) {
                $pendingDispatch->afterResponse();
            }

            return $lockedAttempt;
        });
    }
}

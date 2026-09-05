<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Jobs\RunMarketplaceUpdateAttemptJob;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
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
        return DispatchMarketplaceAttemptAction::run(
            attempt: $attempt,
            queueConnection: $queueConnection,
            queue: $queue,
            jobClass: RunMarketplaceUpdateAttemptJob::class,
        );
    }
}

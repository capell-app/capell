<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Enums\MarketplaceOperationType;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Put an attempt on the queue as whatever it says it is.
 *
 * Every surface that queues a *new* operation already knows which one it is
 * asking for and calls the matching dispatcher directly. This exists for the
 * surfaces that do not: retry takes an existing attempt and has no business
 * deciding what it was. Before the operation column existed there was only one
 * answer, so retry hard-coded the install job — which, once an attempt can be
 * an uninstall, means retrying a failed uninstall would install the package
 * again.
 */
final class DispatchMarketplaceOperationAttemptAction
{
    use AsFake;
    use AsObject;

    public function handle(
        MarketplaceInstallAttempt $attempt,
        string $queueConnection,
        string $queue,
    ): MarketplaceInstallAttempt {
        return match ($attempt->operation) {
            MarketplaceOperationType::Install => DispatchMarketplaceInstallAttemptAction::run(
                attempt: $attempt,
                queueConnection: $queueConnection,
                queue: $queue,
            ),
            MarketplaceOperationType::Update => DispatchMarketplaceUpdateAttemptAction::run(
                attempt: $attempt,
                queueConnection: $queueConnection,
                queue: $queue,
            ),
            MarketplaceOperationType::Uninstall => DispatchMarketplaceUninstallAttemptAction::run(
                attempt: $attempt,
                queueConnection: $queueConnection,
                queue: $queue,
            ),
        };
    }
}

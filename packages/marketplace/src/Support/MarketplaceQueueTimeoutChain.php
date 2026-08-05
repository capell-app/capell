<?php

declare(strict_types=1);

namespace Capell\Marketplace\Support;

use Capell\Marketplace\Jobs\RunMarketplaceInstallAttemptJob;

/**
 * The one description of the Composer / job / queue timeout chain.
 *
 * A queue connection whose retry_after is at or below the installer job timeout
 * re-dispatches a job that is still running Composer, so the same install runs
 * twice. Readiness, the install preflight, and the operations doctor all need
 * that answer, and a second copy of the rule is a second answer waiting to drift
 * from the job's own constants.
 */
final readonly class MarketplaceQueueTimeoutChain
{
    private function __construct(
        public string $connectionName,
        public ?int $retryAfterSeconds,
        public int $jobTimeoutSeconds,
        public int $composerTimeoutSeconds,
    ) {}

    public static function resolve(): self
    {
        $connectionName = (string) config('capell-marketplace.marketplace.operations_queue_connection', 'database');
        $retryAfter = config('queue.connections.' . $connectionName . '.retry_after');

        return new self(
            connectionName: $connectionName,
            retryAfterSeconds: is_numeric($retryAfter) ? (int) $retryAfter : null,
            jobTimeoutSeconds: RunMarketplaceInstallAttemptJob::jobTimeoutSeconds(),
            composerTimeoutSeconds: RunMarketplaceInstallAttemptJob::composerTimeoutSeconds(),
        );
    }

    /**
     * A connection with no numeric retry_after (sync, sqs, and friends) never
     * re-dispatches on a timer, so it cannot break the chain.
     */
    public function isSafe(): bool
    {
        return $this->retryAfterSeconds === null
            || $this->retryAfterSeconds > $this->jobTimeoutSeconds;
    }
}

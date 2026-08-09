<?php

declare(strict_types=1);

namespace Capell\Marketplace\Jobs;

use Capell\Marketplace\Support\MarketplaceWorkerHeartbeat;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The probe half of the worker heartbeat: the scheduler dispatches it, and only
 * a worker consuming the Marketplace queue can ever run it. That it ran is the
 * whole payload — reaching handle() is the evidence.
 */
final class RecordMarketplaceWorkerHeartbeatJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 30;

    /**
     * A backlog of identical probes says nothing a single one does not, and one
     * that has waited longer than the heartbeat window is no longer evidence of
     * anything current.
     */
    public int $uniqueFor = 60;

    public function __construct()
    {
        $this->onConnection((string) config('capell-marketplace.marketplace.operations_queue_connection', 'database'));
        $this->onQueue((string) config('capell-marketplace.marketplace.operations_queue', 'capell-marketplace'));
    }

    /**
     * A retry budget expressed as a deadline rather than a count, because what
     * a heartbeat is worth depends entirely on when it lands. Retrying inside
     * the freshness window is useful — a transient cache blip should not be
     * reported as "no worker is running" — while a probe that is still failing
     * after the window has closed is no longer evidence of anything current,
     * and the next scheduled probe supersedes it.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addSeconds(MarketplaceWorkerHeartbeat::staleAfterSeconds());
    }

    /**
     * Backoff so the retries inside that window are spread across it rather
     * than exhausted in the first second.
     */
    public function backoff(): int
    {
        return 10;
    }

    public function handle(): void
    {
        MarketplaceWorkerHeartbeat::record();
    }

    /**
     * The probe failing is itself operational news: the worker reached the job
     * and still could not record it, which readiness would otherwise report as
     * an absent worker.
     */
    public function failed(?Throwable $exception): void
    {
        Log::warning('The Marketplace worker heartbeat probe failed, so readiness will report no recent worker.', [
            'exception' => $exception?->getMessage(),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Capell\Marketplace\Jobs;

use Capell\Marketplace\Support\MarketplaceWorkerHeartbeat;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The probe half of the worker heartbeat: the scheduler dispatches it, and only
 * a worker consuming the Marketplace queue can ever run it. That it ran is the
 * whole payload — reaching handle() is the evidence.
 */
final class RecordMarketplaceWorkerHeartbeatJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

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

    public function handle(): void
    {
        MarketplaceWorkerHeartbeat::record();
    }
}

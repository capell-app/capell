<?php

declare(strict_types=1);

namespace Capell\Marketplace\Jobs;

use Capell\Marketplace\Actions\ResumeMarketplaceInstallFlowAction;
use Capell\Marketplace\Data\MarketplaceInstallActorData;
use Capell\Marketplace\Enums\MarketplaceInstallFlowSessionStatus;
use Capell\Marketplace\Models\MarketplaceInstallFlowSession;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ResumeMarketplaceInstallFlowJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 900;

    /**
     * Pinned to the Marketplace connection and queue rather than the
     * application default. A host with a dedicated Marketplace worker runs only
     * that queue, so an inherited default lands this job on a queue nothing is
     * consuming and the returning install flow silently never resumes.
     */
    public function __construct(
        public readonly int $sessionId,
        public readonly MarketplaceInstallActorData $actor,
    ) {
        $this->onConnection((string) config('capell-marketplace.marketplace.operations_queue_connection', 'database'));
        $this->onQueue((string) config('capell-marketplace.marketplace.operations_queue', 'capell-marketplace'));
    }

    public function handle(): void
    {
        $session = MarketplaceInstallFlowSession::query()->find($this->sessionId);

        if (! $session instanceof MarketplaceInstallFlowSession
            || ! in_array($session->status, [
                MarketplaceInstallFlowSessionStatus::Returned,
                MarketplaceInstallFlowSessionStatus::Failed,
            ], true)) {
            return;
        }

        ResumeMarketplaceInstallFlowAction::run($session, $this->actor);
    }
}

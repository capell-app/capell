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

    public function __construct(
        public readonly int $sessionId,
        public readonly MarketplaceInstallActorData $actor,
    ) {}

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

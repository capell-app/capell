<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Enums\MarketplaceInstallAttemptEventLevel;
use Capell\Marketplace\Enums\MarketplaceInstallFailureStage;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class ClaimMarketplaceInstallDeploymentPublicationAction
{
    use AsFake;
    use AsObject;

    public function handle(MarketplaceInstallAttempt $attempt): ?MarketplaceInstallAttempt
    {
        return DB::transaction(function () use ($attempt): ?MarketplaceInstallAttempt {
            $lockedAttempt = MarketplaceInstallAttempt::query()
                ->whereKey((int) $attempt->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedAttempt->status !== MarketplaceInstallIntentStatus::Queued) {
                return null;
            }

            $lockedAttempt->forceFill([
                'current_stage' => MarketplaceInstallFailureStage::DeploymentHandoff->value,
                'heartbeat_at' => now(),
            ])->save();

            RecordMarketplaceInstallAttemptEventAction::run(
                attempt: $lockedAttempt,
                level: MarketplaceInstallAttemptEventLevel::Info,
                message: (string) __('capell-marketplace::marketplace.operations.timeline_deployment_publication_claimed'),
                stage: MarketplaceInstallFailureStage::DeploymentHandoff,
            );

            return $lockedAttempt->refresh();
        });
    }
}

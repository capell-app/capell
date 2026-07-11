<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Lorisleiva\Actions\Concerns\AsAction;

final class ResolveMarketplaceInstallOperationAction
{
    use AsAction;

    public function handle(MarketplaceInstallAttempt $attempt): bool
    {
        if (in_array($attempt->status, [
            MarketplaceInstallIntentStatus::Queued,
            MarketplaceInstallIntentStatus::Running,
            MarketplaceInstallIntentStatus::CancelRequested,
        ], true)) {
            return false;
        }

        $attempt->forceFill(['resolved_at' => now()])->save();
        resolve(BuildMarketplaceInstallOperationsSummaryAction::class)->forget();

        return true;
    }
}

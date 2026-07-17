<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class ResolveMarketplaceInstallOperationAction
{
    use AsFake;
    use AsObject;

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

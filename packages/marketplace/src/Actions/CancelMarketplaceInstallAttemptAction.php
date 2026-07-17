<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class CancelMarketplaceInstallAttemptAction
{
    use AsFake;
    use AsObject;

    public function handle(MarketplaceInstallAttempt $attempt): MarketplaceInstallAttempt
    {
        $attempt->refresh();

        if ($attempt->status === MarketplaceInstallIntentStatus::Queued) {
            $updated = MarketplaceInstallAttempt::query()
                ->whereKey($attempt->getKey())
                ->where('status', MarketplaceInstallIntentStatus::Queued->value)
                ->update([
                    'status' => MarketplaceInstallIntentStatus::Cancelled->value,
                    'cancel_requested_at' => now(),
                    'cancelled_at' => now(),
                    'completed_at' => now(),
                    'resolved_at' => now(),
                    'updated_at' => now(),
                ]);

            $attempt->refresh();

            if ($updated === 1) {
                return $attempt;
            }
        }

        if ($attempt->status === MarketplaceInstallIntentStatus::Running) {
            MarketplaceInstallAttempt::query()
                ->whereKey($attempt->getKey())
                ->where('status', MarketplaceInstallIntentStatus::Running->value)
                ->update([
                    'status' => MarketplaceInstallIntentStatus::CancelRequested->value,
                    'cancel_requested_at' => now(),
                    'updated_at' => now(),
                ]);

            $attempt->refresh();
        }

        return $attempt;
    }
}

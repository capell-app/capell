<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class FindActiveMarketplaceInstallOperationAction
{
    use AsFake;
    use AsObject;

    public function handle(string $composerName): ?MarketplaceInstallAttempt
    {
        return MarketplaceInstallAttempt::query()
            ->where('composer_name', $composerName)
            ->whereIn('status', [
                MarketplaceInstallIntentStatus::Queued->value,
                MarketplaceInstallIntentStatus::Running->value,
                MarketplaceInstallIntentStatus::CancelRequested->value,
            ])
            ->latest()
            ->first();
    }
}

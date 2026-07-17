<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Core\Facades\CapellCore;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Models\MarketplaceInstallIntent;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class ResolvePendingThemeInstallsAction
{
    use AsFake;
    use AsObject;

    public function handle(): int
    {
        $resolvedCount = 0;

        MarketplaceInstallIntent::query()
            ->where('kind', 'theme')
            ->where('status', MarketplaceInstallIntentStatus::Pending)
            ->each(function (MarketplaceInstallIntent $intent) use (&$resolvedCount): void {
                if (! CapellCore::hasPackage($intent->composer_name)) {
                    return;
                }

                $intent->status = MarketplaceInstallIntentStatus::Resolved;
                /** @phpstan-ignore assign.propertyType (The immutable_datetime cast converts Laravel's current-time Carbon value on assignment.) */
                $intent->resolved_at = now();
                $intent->save();

                $resolvedCount++;
            });

        return $resolvedCount;
    }
}

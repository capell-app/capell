<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Data\ExtensionListingData;
use Capell\Marketplace\Data\MarketplaceInstallEligibilityData;
use Capell\Marketplace\Models\MarketplaceInstance;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

final class AssertMarketplaceInstallAllowedAction
{
    use AsFake;
    use AsObject;

    public function handle(
        ExtensionListingData $listing,
        ?MarketplaceInstance $instance,
        ?string $domain = null,
        string $action = 'install',
        ?MarketplaceInstallEligibilityData $remoteEligibility = null,
    ): MarketplaceInstallEligibilityData {
        $eligibility = ResolveMarketplaceInstallEligibilityAction::run(
            listing: $listing,
            instance: $instance,
            domain: $domain,
            action: $action,
            remoteEligibility: $remoteEligibility,
        );

        if ($eligibility->blocksInstall()) {
            Log::warning('capell-marketplace: install.blocked', [
                'slug' => $listing->slug,
                'composer_name' => $listing->composerName,
                'reason' => $eligibility->blockReason ?? 'blocked',
            ]);

            throw new RuntimeException($eligibility->blockReason ?? 'blocked');
        }

        Log::info('capell-marketplace: install.allowed', [
            'slug' => $listing->slug,
            'composer_name' => $listing->composerName,
        ]);

        return $eligibility;
    }
}

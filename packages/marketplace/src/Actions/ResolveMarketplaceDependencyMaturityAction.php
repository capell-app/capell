<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Data\ExtensionListingData;
use Capell\Marketplace\Services\MarketplaceClient;
use Lorisleiva\Actions\Concerns\AsAction;

final class ResolveMarketplaceDependencyMaturityAction
{
    use AsAction;

    public function __construct(private readonly MarketplaceClient $marketplace) {}

    /** @return array<string, string> */
    public function handle(ExtensionListingData $listing): array
    {
        $maturity = [];
        $pending = $listing->requiredDependencies;

        while ($pending !== []) {
            $composerName = array_shift($pending);

            if (! is_string($composerName) || isset($maturity[$composerName])) {
                continue;
            }

            $dependency = $this->marketplace->extensionsByComposerNames([$composerName], allowCache: false)[$composerName] ?? null;

            if (! $dependency instanceof ExtensionListingData) {
                $maturity[$composerName] = 'missing';

                continue;
            }

            $maturity[$composerName] = $dependency->maturity;
            $pending = [...$pending, ...$dependency->requiredDependencies];
        }

        ksort($maturity);

        return $maturity;
    }
}

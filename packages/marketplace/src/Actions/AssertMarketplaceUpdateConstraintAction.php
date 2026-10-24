<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Contracts\MarketplaceInstalledPackageVersionResolver;
use Capell\Marketplace\Support\MarketplaceComposerCoordinates;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class AssertMarketplaceUpdateConstraintAction
{
    use AsFake;
    use AsObject;

    public function __construct(private readonly MarketplaceInstalledPackageVersionResolver $installedVersions) {}

    /** Call while holding the release's Composer operation lock. */
    public function handle(string $composerName, string $versionConstraint): void
    {
        $currentVersion = $this->installedVersions->prettyVersion($composerName);

        if ($currentVersion === null || $currentVersion === '') {
            throw ValidationException::withMessages([
                'composer_name' => __('capell-marketplace::marketplace.updates.not_installed', ['package' => $composerName]),
            ]);
        }

        MarketplaceComposerCoordinates::assertUpgrade($composerName, $versionConstraint, $currentVersion);
    }
}

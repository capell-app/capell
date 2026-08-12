<?php

declare(strict_types=1);

use Capell\Marketplace\Contracts\MarketplaceInstalledPackageVersionResolver;
use Capell\Marketplace\Support\ComposerInstalledPackageVersionResolver;

it('binds the production installed version resolver through the Marketplace service provider', function (): void {
    expect(resolve(MarketplaceInstalledPackageVersionResolver::class))
        ->toBeInstanceOf(ComposerInstalledPackageVersionResolver::class)
        ->and(resolve(MarketplaceInstalledPackageVersionResolver::class)->prettyVersion('package/that-is-not-installed'))
        ->toBeNull();
});

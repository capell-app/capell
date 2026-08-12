<?php

declare(strict_types=1);

namespace Capell\Marketplace\Support;

use Capell\Marketplace\Contracts\MarketplaceInstalledPackageVersionResolver;
use Composer\InstalledVersions;

final class ComposerInstalledPackageVersionResolver implements MarketplaceInstalledPackageVersionResolver
{
    public function prettyVersion(string $composerName): ?string
    {
        if (! InstalledVersions::isInstalled($composerName)) {
            return null;
        }

        return InstalledVersions::getPrettyVersion($composerName);
    }
}

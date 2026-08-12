<?php

declare(strict_types=1);

namespace Capell\Marketplace\Contracts;

interface MarketplaceInstalledPackageVersionResolver
{
    public function prettyVersion(string $composerName): ?string;
}

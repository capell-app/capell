<?php

declare(strict_types=1);

namespace Capell\Marketplace\Support;

use Capell\Marketplace\Contracts\MarketplaceInstalledPackageVersionResolver;

final readonly class FoundationInstalledVersionResolver
{
    private const array COMPOSER_NAMES = [
        'capell-app/capell',
        'capell-app/core',
    ];

    public function __construct(
        private MarketplaceInstalledPackageVersionResolver $versions,
    ) {}

    public function prettyVersion(): ?string
    {
        foreach (self::COMPOSER_NAMES as $composerName) {
            $version = $this->versions->prettyVersion($composerName);

            if ($version !== null) {
                return $version;
            }
        }

        return null;
    }
}

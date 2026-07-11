<?php

declare(strict_types=1);

namespace Capell\Core\Support\Install;

use Composer\InstalledVersions;

class DeveloperToolingInstallationState
{
    /** @var array<int, string> */
    private const array REQUIRED_PACKAGE_NAMES = [
        'capell-app/agent-bridge',
        'laravel/boost',
    ];

    public function isInstalled(): bool
    {
        return $this->missingPackageNames() === [];
    }

    /**
     * @return array<int, string>
     */
    public function missingPackageNames(): array
    {
        return collect(self::REQUIRED_PACKAGE_NAMES)
            ->reject(static fn (string $packageName): bool => InstalledVersions::isInstalled($packageName))
            ->values()
            ->all();
    }
}

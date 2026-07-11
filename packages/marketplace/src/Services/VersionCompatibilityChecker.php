<?php

declare(strict_types=1);

namespace Capell\Marketplace\Services;

use Capell\Marketplace\Data\ExtensionListingData;
use Composer\InstalledVersions;
use Composer\Semver\Semver;

final class VersionCompatibilityChecker
{
    public function isCompatible(ExtensionListingData $listing): bool
    {
        return $this->checkConstraint($listing->capellVersionConstraint, 'capell/core')
            && $this->checkConstraint($listing->laravelVersionConstraint, 'laravel/framework')
            && $this->checkConstraint($listing->filamentVersionConstraint, 'filament/filament')
            && $this->checkConstraint($listing->livewireVersionConstraint, 'livewire/livewire');
    }

    /** @return array<string, string> */
    public function compatibilityDetails(ExtensionListingData $listing): array
    {
        return [
            'capell' => $this->statusFor($listing->capellVersionConstraint, 'capell/core'),
            'laravel' => $this->statusFor($listing->laravelVersionConstraint, 'laravel/framework'),
            'filament' => $this->statusFor($listing->filamentVersionConstraint, 'filament/filament'),
            'livewire' => $this->statusFor($listing->livewireVersionConstraint, 'livewire/livewire'),
        ];
    }

    private function checkConstraint(?string $constraint, string $package): bool
    {
        if ($constraint === null) {
            return true;
        }

        $installed = $this->getInstalledVersion($package);

        if ($installed === null) {
            return true;
        }

        return Semver::satisfies($installed, $constraint);
    }

    private function statusFor(?string $constraint, string $package): string
    {
        if ($constraint === null) {
            return 'ok';
        }

        $installed = $this->getInstalledVersion($package);

        if ($installed === null) {
            return 'unknown';
        }

        return Semver::satisfies($installed, $constraint) ? 'ok' : 'incompatible';
    }

    private function getInstalledVersion(string $package): ?string
    {
        if (! InstalledVersions::isInstalled($package)) {
            return null;
        }

        return InstalledVersions::getPrettyVersion($package);
    }
}

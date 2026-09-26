<?php

declare(strict_types=1);

namespace Capell\Marketplace\Services;

use Capell\Marketplace\Contracts\MarketplaceInstalledPackageVersionResolver;
use Capell\Marketplace\Data\ExtensionListingData;
use Capell\Marketplace\Support\FoundationInstalledVersionResolver;
use Composer\Semver\Semver;

final class VersionCompatibilityChecker
{
    public function __construct(
        private readonly FoundationInstalledVersionResolver $foundationVersion,
        private readonly MarketplaceInstalledPackageVersionResolver $installedVersions,
    ) {}

    public function isCompatible(ExtensionListingData $listing): bool
    {
        return $this->checkConstraint($listing->capellVersionConstraint, $this->foundationVersion->prettyVersion())
            && $this->checkConstraint($listing->laravelVersionConstraint, $this->installedVersions->prettyVersion('laravel/framework'))
            && $this->checkConstraint($listing->filamentVersionConstraint, $this->installedVersions->prettyVersion('filament/filament'))
            && $this->checkConstraint($listing->livewireVersionConstraint, $this->installedVersions->prettyVersion('livewire/livewire'));
    }

    /** @return array<string, string> */
    public function compatibilityDetails(ExtensionListingData $listing): array
    {
        return [
            'capell' => $this->statusFor($listing->capellVersionConstraint, $this->foundationVersion->prettyVersion()),
            'laravel' => $this->statusFor($listing->laravelVersionConstraint, $this->installedVersions->prettyVersion('laravel/framework')),
            'filament' => $this->statusFor($listing->filamentVersionConstraint, $this->installedVersions->prettyVersion('filament/filament')),
            'livewire' => $this->statusFor($listing->livewireVersionConstraint, $this->installedVersions->prettyVersion('livewire/livewire')),
        ];
    }

    private function checkConstraint(?string $constraint, ?string $installed): bool
    {
        if ($constraint === null) {
            return true;
        }

        if ($installed === null) {
            return true;
        }

        return Semver::satisfies($installed, $constraint);
    }

    private function statusFor(?string $constraint, ?string $installed): string
    {
        if ($constraint === null) {
            return 'ok';
        }

        if ($installed === null) {
            return 'unknown';
        }

        return Semver::satisfies($installed, $constraint) ? 'ok' : 'incompatible';
    }
}

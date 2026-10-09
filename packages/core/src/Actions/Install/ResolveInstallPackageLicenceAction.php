<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Data\PackageData;
use Capell\Core\Enums\InstallPackageLicenceState;
use Lorisleiva\Actions\Concerns\AsObject;

final class ResolveInstallPackageLicenceAction
{
    use AsObject;

    public function handle(PackageData $package): InstallPackageLicenceState
    {
        if ($package->installState === 'unavailable' || ($package->installEligibility['state'] ?? null) === 'unavailable') {
            return InstallPackageLicenceState::Unavailable;
        }

        // Public catalogue authorization is not an account-specific licence check.
        return match (true) {
            $package->isPaid === true => InstallPackageLicenceState::Required,
            $package->isPaid === false => InstallPackageLicenceState::Free,
            $package->tier === 'free' => InstallPackageLicenceState::Free,
            default => InstallPackageLicenceState::Unknown,
        };
    }
}

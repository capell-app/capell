<?php

declare(strict_types=1);

namespace Capell\Core\Support\Install;

use Capell\Core\Data\PackageData;

/**
 * Single source of truth for whether a package takes part in an install lifecycle
 * phase, shared by the install plan and the executors so they cannot disagree.
 */
final class PackageLifecycleSteps
{
    public static function hasAfterInstall(PackageData $package): bool
    {
        $command = $package->getAfterInstallCommand();

        if ($command !== null && $command !== '') {
            return true;
        }

        $action = $package->getAfterInstallAction();

        return $action !== null && $action !== '';
    }
}

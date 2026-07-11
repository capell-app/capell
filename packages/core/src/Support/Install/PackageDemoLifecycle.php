<?php

declare(strict_types=1);

namespace Capell\Core\Support\Install;

use Capell\Core\Data\InstallInputData;
use Capell\Core\Data\PackageData;

final class PackageDemoLifecycle
{
    public static function shouldRunDemo(InstallInputData $inputData, PackageData $package): bool
    {
        if ($package->getDemoCommand() === null || $package->getDemoCommand() === '') {
            return false;
        }

        $themeKey = $package->getThemeKey();

        if ($themeKey === null) {
            return true;
        }

        return $inputData->selectedThemeKey !== null
            && $inputData->selectedThemeKey !== ''
            && $themeKey === $inputData->selectedThemeKey;
    }
}

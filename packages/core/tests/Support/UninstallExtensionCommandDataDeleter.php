<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

use Capell\Core\Contracts\Extensions\DeletesExtensionData;
use Capell\Core\Data\PackageData;
use Override;

final class UninstallExtensionCommandDataDeleter implements DeletesExtensionData
{
    /** @var list<string> */
    public static array $deletedPackages = [];

    #[Override]
    public static function compatibleCapellApiVersion(): string
    {
        return '1.0';
    }

    #[Override]
    public function deleteExtensionData(PackageData $package): void
    {
        self::$deletedPackages[] = $package->name;
    }
}

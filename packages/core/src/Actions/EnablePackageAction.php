<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Data\PackageData;
use Capell\Core\Facades\CapellCore;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static void run(PackageData $package)
 */
class EnablePackageAction
{
    use AsObject;

    public function handle(PackageData $package): void
    {
        if ($package->getKind() === 'bundle') {
            foreach ($package->getRequirements() as $memberName) {
                CapellCore::markPackageInstalled($memberName);
            }
        }

        CapellCore::markPackageInstalled($package->name);
    }
}

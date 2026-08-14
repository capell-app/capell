<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Data\PackageData;
use Capell\Core\Facades\CapellCore;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static void run(PackageData $package, ?string $actor = null)
 */
class EnablePackageAction
{
    use AsFake;
    use AsObject;

    public function handle(PackageData $package, ?string $actor = null): void
    {
        if ($package->getKind() === 'bundle') {
            foreach ($package->getRequirements() as $memberName) {
                CapellCore::markPackageInstalled($memberName, $actor);
            }
        }

        CapellCore::markPackageInstalled($package->name, $actor);
    }
}

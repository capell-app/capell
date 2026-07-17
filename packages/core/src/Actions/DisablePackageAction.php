<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Data\PackageData;
use Capell\Core\Facades\CapellCore;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static void run(PackageData $package)
 */
class DisablePackageAction
{
    use AsFake;
    use AsObject;

    public function handle(PackageData $package): void
    {
        CapellCore::markPackageDisabled($package->name);
    }
}

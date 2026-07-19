<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Core\Facades\CapellCore;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class PackageIsAvailableForLifecycleAction
{
    use AsFake;
    use AsObject;

    public function handle(string $composerName): bool
    {
        return CapellCore::hasPackage($composerName)
            && CapellCore::isPackageAvailable($composerName)
            && ! CapellCore::isPackageInstalled($composerName);
    }
}

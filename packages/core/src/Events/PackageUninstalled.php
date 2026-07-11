<?php

declare(strict_types=1);

namespace Capell\Core\Events;

use Capell\Core\Data\PackageData;

class PackageUninstalled
{
    public function __construct(public PackageData $package) {}
}

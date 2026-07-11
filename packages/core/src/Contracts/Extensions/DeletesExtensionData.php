<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Extensions;

use Capell\Core\Data\PackageData;

interface DeletesExtensionData extends ExtensionContribution
{
    public function deleteExtensionData(PackageData $package): void;
}

<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Extensions;

use Capell\Admin\Data\Extensions\ExtensionDependencyBlockData;
use Capell\Admin\Data\Extensions\ExtensionOperationPackageData;

interface ExtensionDependencyProvider
{
    public const string TAG = 'capell.admin.extension-dependency-provider';

    /** @return list<ExtensionDependencyBlockData> */
    public function blockers(ExtensionOperationPackageData $package): array;
}

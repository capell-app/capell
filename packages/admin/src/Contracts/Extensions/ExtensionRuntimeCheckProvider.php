<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Extensions;

use Capell\Admin\Data\Extensions\ExtensionOperationPackageData;
use Capell\Admin\Data\Extensions\ExtensionRuntimeCompatibilityData;

interface ExtensionRuntimeCheckProvider
{
    public const string TAG = 'capell.admin.extension-runtime-check-provider';

    /** @return list<ExtensionRuntimeCompatibilityData> */
    public function checks(ExtensionOperationPackageData $package): array;
}

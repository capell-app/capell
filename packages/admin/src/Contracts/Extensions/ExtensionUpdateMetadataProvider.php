<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Extensions;

use Capell\Admin\Data\Extensions\ExtensionOperationPackageData;
use Capell\Admin\Data\Extensions\ExtensionUpdateReadinessData;

interface ExtensionUpdateMetadataProvider
{
    public const string TAG = 'capell.admin.extension-update-metadata-provider';

    public function updateReadiness(ExtensionOperationPackageData $package): ?ExtensionUpdateReadinessData;
}

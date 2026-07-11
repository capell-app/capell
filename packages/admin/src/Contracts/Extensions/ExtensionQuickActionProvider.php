<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Extensions;

use Capell\Admin\Data\Extensions\ExtensionOperationPackageData;
use Capell\Admin\Data\Extensions\ExtensionQuickActionData;

interface ExtensionQuickActionProvider
{
    public const string TAG = 'capell.admin.extension-quick-action-provider';

    /** @return list<ExtensionQuickActionData> */
    public function actions(ExtensionOperationPackageData $package): array;
}

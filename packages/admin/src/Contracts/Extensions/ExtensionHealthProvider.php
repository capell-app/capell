<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Extensions;

use Capell\Admin\Data\Extensions\ExtensionHealthAlertData;
use Capell\Admin\Data\Extensions\ExtensionOperationPackageData;

interface ExtensionHealthProvider
{
    public const string TAG = 'capell.admin.extension-health-provider';

    /** @return list<ExtensionHealthAlertData> */
    public function alerts(ExtensionOperationPackageData $package): array;
}

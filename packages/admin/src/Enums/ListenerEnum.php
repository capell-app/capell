<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

enum ListenerEnum: string
{
    case AfterSave = 'afterSave';

    case SiteTreeRebuilt = 'siteTreeRebuilt';

    case ValidateCustomType = 'validateCustomType';
}

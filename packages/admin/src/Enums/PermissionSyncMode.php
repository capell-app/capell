<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

enum PermissionSyncMode
{
    case Install;
    case Upgrade;
}

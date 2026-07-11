<?php

declare(strict_types=1);

namespace Capell\Core\Enums\Upgrade;

enum UpgradeRunEventLevel: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Error = 'error';
    case Success = 'success';
}

<?php

declare(strict_types=1);

namespace Capell\Marketplace\Enums;

enum MarketplaceInstallAttemptEventLevel: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Error = 'error';
    case Success = 'success';
}

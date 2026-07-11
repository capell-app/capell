<?php

declare(strict_types=1);

namespace Capell\Marketplace\Enums;

enum MarketplaceInstallFlowSessionStatus: string
{
    case Pending = 'pending';
    case Redirected = 'redirected';
    case Returned = 'returned';
    case Authorizing = 'authorizing';
    case Queued = 'queued';
    case Completed = 'completed';
    case Expired = 'expired';
    case Failed = 'failed';
}

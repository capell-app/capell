<?php

declare(strict_types=1);

namespace Capell\Marketplace\Enums;

enum MarketplaceAccountConnectionSessionStatus: string
{
    case Pending = 'pending';
    case Completing = 'completing';
    case Completed = 'completed';
    case Expired = 'expired';
    case Failed = 'failed';
}

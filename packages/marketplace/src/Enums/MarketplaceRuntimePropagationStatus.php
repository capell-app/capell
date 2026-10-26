<?php

declare(strict_types=1);

namespace Capell\Marketplace\Enums;

enum MarketplaceRuntimePropagationStatus: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case RecoveryRequired = 'recovery_required';
}

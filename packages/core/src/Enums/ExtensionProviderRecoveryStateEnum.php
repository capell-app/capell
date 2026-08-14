<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

enum ExtensionProviderRecoveryStateEnum: string
{
    case Healthy = 'healthy';
    case Quarantined = 'quarantined';
}

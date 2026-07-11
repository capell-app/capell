<?php

declare(strict_types=1);

namespace Capell\Core\Enums\Upgrade;

enum UpgradeQueueStatus: string
{
    case Queued = 'queued';
    case ManualRequired = 'manual_required';
}

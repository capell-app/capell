<?php

declare(strict_types=1);

namespace Capell\Core\Enums\Upgrade;

enum UpgradeReadinessResult: string
{
    case Ready = 'ready';
    case ManualRequired = 'manual_required';
}

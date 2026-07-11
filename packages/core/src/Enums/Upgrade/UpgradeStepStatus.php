<?php

declare(strict_types=1);

namespace Capell\Core\Enums\Upgrade;

enum UpgradeStepStatus: string
{
    case DryRun = 'dry-run';
    case Failed = 'failed';
    case RolledBack = 'rolled_back';
    case Skipped = 'skipped';
    case Success = 'success';
    case Superseded = 'superseded';

    public function completedUpgradeRun(): bool
    {
        return in_array($this, [self::DryRun, self::Success], true);
    }
}

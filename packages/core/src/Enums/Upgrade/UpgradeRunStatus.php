<?php

declare(strict_types=1);

namespace Capell\Core\Enums\Upgrade;

enum UpgradeRunStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case ManualRequired = 'manual_required';

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Succeeded,
            self::Failed,
            self::ManualRequired,
        ], true);
    }
}

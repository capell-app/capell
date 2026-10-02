<?php

declare(strict_types=1);

namespace Capell\Installer\Enums;

enum InstallerRunStatus: string
{
    case Unknown = 'unknown';
    case Idle = 'idle';
    case Pending = 'pending';
    case Queued = 'queued';
    case Running = 'running';
    case Complete = 'complete';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isActive(): bool
    {
        return in_array($this, [self::Pending, self::Queued, self::Running], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Complete, self::Failed, self::Cancelled], true);
    }
}

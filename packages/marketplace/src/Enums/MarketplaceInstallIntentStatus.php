<?php

declare(strict_types=1);

namespace Capell\Marketplace\Enums;

enum MarketplaceInstallIntentStatus: string
{
    case Pending = 'pending';
    case Resolved = 'resolved';
    case Dismissed = 'dismissed';
    case AuthorizationFailed = 'authorization_failed';
    case Blocked = 'blocked';
    case CommandFallback = 'command_fallback';
    case DeploymentFailed = 'deployment_failed';
    case DeploymentPublished = 'deployment_published';
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case TimedOut = 'timed_out';
    case CancelRequested = 'cancel_requested';
    case Cancelled = 'cancelled';

    public function isActiveInstallOperation(): bool
    {
        return in_array($this, [
            self::Queued,
            self::Running,
            self::CancelRequested,
        ], true);
    }
}

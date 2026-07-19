<?php

declare(strict_types=1);

namespace Capell\Marketplace\Enums;

use Capell\Core\Enums\Concerns\HasEnumOptions;
use Filament\Support\Contracts\HasLabel;

enum MarketplaceInstallIntentStatus: string implements HasLabel
{
    use HasEnumOptions;

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

    public function getLabel(): string
    {
        return (string) __('capell-marketplace::marketplace.operations.status_options.' . $this->value);
    }

    public function isActiveInstallOperation(): bool
    {
        return in_array($this, [
            self::Queued,
            self::Running,
            self::CancelRequested,
        ], true);
    }
}

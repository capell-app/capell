<?php

declare(strict_types=1);

namespace Capell\Marketplace\Enums;

use Filament\Support\Contracts\HasLabel;

enum MarketplaceInstallIntentStatus: string implements HasLabel
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
    case AwaitingDeployment = 'awaiting_deployment';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case TimedOut = 'timed_out';
    case CancelRequested = 'cancel_requested';
    case Cancelled = 'cancelled';

    /** @return list<self> */
    public static function activeOperations(): array
    {
        return [
            self::Queued,
            self::AwaitingDeployment,
            self::Running,
            self::CancelRequested,
        ];
    }

    /** @return list<string> */
    public static function activeOperationValues(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::activeOperations());
    }

    public function getLabel(): string
    {
        return (string) __('capell-marketplace::marketplace.install_statuses.' . $this->value);
    }

    /**
     * An attempt waiting for its deployment holds the package exactly as a
     * queued one does: the change is already on its way through the site's
     * deploy pipeline, so a second operation on the same package must wait.
     */
    public function isActiveInstallOperation(): bool
    {
        return in_array($this, self::activeOperations(), true);
    }
}

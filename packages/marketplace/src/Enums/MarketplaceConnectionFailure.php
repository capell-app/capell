<?php

declare(strict_types=1);

namespace Capell\Marketplace\Enums;

enum MarketplaceConnectionFailure: string
{
    case Configuration = 'configuration';
    case Service = 'service';
    case Approval = 'approval';
    case Storage = 'storage';
    case Unreachable = 'unreachable';
    case Rejected = 'rejected';

    public function description(): string
    {
        return match ($this) {
            self::Configuration => (string) __('capell-marketplace::marketplace.connection_failures.configuration'),
            self::Service => (string) __('capell-marketplace::marketplace.connection_failures.service'),
            self::Approval => (string) __('capell-marketplace::marketplace.connection_failures.approval'),
            self::Storage => (string) __('capell-marketplace::marketplace.connection_failures.storage'),
            self::Unreachable => (string) __('capell-marketplace::marketplace.connection_failures.unreachable'),
            self::Rejected => (string) __('capell-marketplace::marketplace.connection_failures.rejected'),
        };
    }
}

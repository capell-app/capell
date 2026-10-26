<?php

declare(strict_types=1);

namespace Capell\Marketplace\Enums;

enum MarketplaceConnectionFailure: string
{
    case Configuration = 'configuration';
    case Service = 'service';
    case Approval = 'approval';

    public function description(): string
    {
        return match ($this) {
            self::Configuration => (string) __('capell-marketplace::marketplace.connection_failures.configuration'),
            self::Service => (string) __('capell-marketplace::marketplace.connection_failures.service'),
            self::Approval => (string) __('capell-marketplace::marketplace.connection_failures.approval'),
        };
    }
}

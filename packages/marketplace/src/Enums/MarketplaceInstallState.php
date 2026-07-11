<?php

declare(strict_types=1);

namespace Capell\Marketplace\Enums;

use Filament\Support\Contracts\HasLabel;

enum MarketplaceInstallState: string implements HasLabel
{
    case FreeAvailable = 'free_available';
    case PurchaseRequired = 'purchase_required';
    case ActivationRequired = 'activation_required';
    case Authorized = 'authorized';
    case Installed = 'installed';
    case Incompatible = 'incompatible';
    case Blocked = 'blocked';

    public function getLabel(): string
    {
        return (string) __('capell-marketplace::marketplace.install_states.' . $this->value);
    }
}

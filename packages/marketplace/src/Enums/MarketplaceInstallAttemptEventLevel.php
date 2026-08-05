<?php

declare(strict_types=1);

namespace Capell\Marketplace\Enums;

use Filament\Support\Contracts\HasLabel;

enum MarketplaceInstallAttemptEventLevel: string implements HasLabel
{
    case Info = 'info';
    case Warning = 'warning';
    case Error = 'error';
    case Success = 'success';

    public function getLabel(): string
    {
        return (string) __('capell-marketplace::marketplace.event_levels.' . $this->value);
    }
}

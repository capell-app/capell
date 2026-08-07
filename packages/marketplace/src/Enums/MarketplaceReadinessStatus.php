<?php

declare(strict_types=1);

namespace Capell\Marketplace\Enums;

use Filament\Support\Contracts\HasLabel;

enum MarketplaceReadinessStatus: string implements HasLabel
{
    case Pass = 'pass';
    case Warn = 'warn';
    case Fail = 'fail';

    public function getLabel(): string
    {
        return (string) __('capell-marketplace::marketplace.readiness.statuses.' . $this->value);
    }
}

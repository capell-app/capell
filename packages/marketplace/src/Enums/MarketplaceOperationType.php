<?php

declare(strict_types=1);

namespace Capell\Marketplace\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * What a marketplace install attempt is actually doing.
 *
 * The row has always been called an "install attempt" because installing was
 * the only thing it could do. It now also carries updates, and Task 7's queued
 * uninstall, so the operation is recorded explicitly rather than inferred from
 * whichever columns happen to be populated.
 */
enum MarketplaceOperationType: string implements HasLabel
{
    case Install = 'install';
    case Update = 'update';
    case Uninstall = 'uninstall';

    public function getLabel(): string
    {
        return (string) __('capell-marketplace::marketplace.operation_types.' . $this->value);
    }
}

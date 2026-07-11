<?php

declare(strict_types=1);

namespace Capell\Marketplace\Enums;

use Filament\Support\Contracts\HasLabel;

enum MarketplaceSort: string implements HasLabel
{
    case Recommended = 'recommended';
    case FeaturedLatest = 'featured_latest';
    case Latest = 'latest';
    case Popular = 'popular';
    case PriceLow = 'price_low';
    case PriceHigh = 'price_high';
    case Name = 'name';

    public function getLabel(): string
    {
        return (string) __('capell-marketplace::marketplace.sort.' . $this->value);
    }
}

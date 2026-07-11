<?php

declare(strict_types=1);

use Capell\Marketplace\Enums\MarketplaceSort;

it('exposes translated labels for every marketplace sort option', function (): void {
    foreach (MarketplaceSort::cases() as $sort) {
        expect($sort->getLabel())
            ->toBe(__('capell-marketplace::marketplace.sort.' . $sort->value));
    }
});

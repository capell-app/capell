<?php

declare(strict_types=1);

use Capell\Marketplace\Enums\ExtensionKind;
use Capell\Marketplace\Enums\MarketplaceSort;

it('labels marketplace extension kinds', function (): void {
    expect(ExtensionKind::Theme->getLabel())->toBe('Theme')
        ->and(ExtensionKind::Widget->getLabel())->toBe('Widget')
        ->and(ExtensionKind::Integration->getLabel())->toBe('Integration')
        ->and(ExtensionKind::Field->getLabel())->toBe('Field')
        ->and(ExtensionKind::Block->getLabel())->toBe('Block')
        ->and(ExtensionKind::Tool->getLabel())->toBe('Tool');
});

it('labels marketplace sort options', function (): void {
    expect(MarketplaceSort::FeaturedLatest->getLabel())->toBe(__('capell-marketplace::marketplace.sort.featured_latest'))
        ->and(MarketplaceSort::Latest->getLabel())->toBe(__('capell-marketplace::marketplace.sort.latest'))
        ->and(MarketplaceSort::Popular->getLabel())->toBe(__('capell-marketplace::marketplace.sort.popular'))
        ->and(MarketplaceSort::PriceLow->getLabel())->toBe(__('capell-marketplace::marketplace.sort.price_low'))
        ->and(MarketplaceSort::PriceHigh->getLabel())->toBe(__('capell-marketplace::marketplace.sort.price_high'))
        ->and(MarketplaceSort::Name->getLabel())->toBe(__('capell-marketplace::marketplace.sort.name'));
});

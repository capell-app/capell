<?php

declare(strict_types=1);

use Capell\Core\Support\Marketplace\MarketplaceAssetUrl;

it('resolves relative marketplace asset paths against the configured marketplace web url', function (): void {
    config([
        'capell-marketplace.marketplace.web_url' => null,
        'capell.marketplace_web_url' => 'https://capell-test.app',
    ]);

    expect(MarketplaceAssetUrl::webUrl())->toBe('https://capell-test.app')
        ->and(MarketplaceAssetUrl::toUrl('/docs/assets/marketplace/extension-card.jpg'))
        ->toBe('https://capell-test.app/docs/assets/marketplace/extension-card.jpg')
        ->and(MarketplaceAssetUrl::toUrl('docs/assets/marketplace/theme-card.jpg'))
        ->toBe('https://capell-test.app/docs/assets/marketplace/theme-card.jpg');
});

it('keeps absolute and data image urls unchanged', function (): void {
    config(['capell.marketplace_web_url' => 'https://capell-test.app']);

    expect(MarketplaceAssetUrl::toUrl('https://cdn.example.test/card.jpg'))->toBe('https://cdn.example.test/card.jpg')
        ->and(MarketplaceAssetUrl::toUrl('http://cdn.example.test/card.jpg'))->toBe('http://cdn.example.test/card.jpg')
        ->and(MarketplaceAssetUrl::toUrl('data:image/svg+xml;base64,AAAA'))->toBe('data:image/svg+xml;base64,AAAA');
});

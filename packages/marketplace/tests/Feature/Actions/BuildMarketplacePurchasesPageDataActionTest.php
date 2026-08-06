<?php

declare(strict_types=1);

use Capell\Core\Enums\ExtensionStatusEnum;
use Capell\Core\Models\CapellExtension;
use Capell\Marketplace\Actions\BuildMarketplacePurchasesPageDataAction;
use Capell\Marketplace\Models\MarketplaceInstance;
use Capell\Marketplace\Support\MarketplaceInstanceResolver;

it('builds purchases and installed licence data from authoritative stored state', function (): void {
    MarketplaceInstance::query()->create([
        'instance_id' => '018f47a2-62da-7ca4-b732-bd3c715db1bf',
        'signing_secret_encrypted' => 'test-signing-secret',
        'connection_metadata' => [
            'commercial' => [
                'currency' => 'GBP',
                'renewal_url' => 'https://marketplace.example.test/account/renew',
                'support_url' => 'https://marketplace.example.test/support',
                'membership_comparison' => [
                    'name' => 'Capell Membership',
                    'price_cents' => 19900,
                    'renewal_price_cents' => 15920,
                    'currency' => 'GBP',
                    'included_product_count' => 38,
                    'named_user_limit' => 5,
                ],
                'new_membership_product_count' => 4,
                'priority_support_price_cents' => 4900,
                'purchases' => [
                    [
                        'name' => 'SEO Suite',
                        'status' => 'active',
                        'access_ends_at' => '2027-08-05T00:00:00Z',
                    ],
                ],
            ],
        ],
        'last_heartbeat_at' => now(),
    ]);
    resolve(MarketplaceInstanceResolver::class)->forget();

    CapellExtension::query()->create([
        'composer_name' => 'capell-app/seo-suite',
        'name' => 'SEO Suite',
        'version' => '1.0.0',
        'status' => ExtensionStatusEnum::Enabled,
        'is_paid_marketplace_extension' => true,
        'marketplace_runtime_status' => 'active',
        'marketplace_activation_checked_at' => now(),
    ]);
    CapellExtension::query()->create([
        'composer_name' => 'capell-app/free-extension',
        'name' => 'Free Extension',
        'version' => '1.0.0',
        'status' => ExtensionStatusEnum::Enabled,
        'is_paid_marketplace_extension' => false,
    ]);

    $data = BuildMarketplacePurchasesPageDataAction::run();

    expect($data['currency'])->toBe('GBP')
        ->and($data['renewal_url'])->toBe('https://marketplace.example.test/account/renew')
        ->and($data['support_url'])->toBe('https://marketplace.example.test/support')
        ->and($data['membership_price'])->toBe('£199.00')
        ->and($data['membership_renewal_price'])->toBe('£159.20')
        ->and($data['priority_support_price'])->toBe('£49.00')
        ->and($data['new_membership_product_count'])->toBe(4)
        ->and($data['purchases'])->toHaveCount(1)
        ->and($data['purchases'][0]['name'])->toBe('SEO Suite')
        ->and($data['installed'])->toHaveCount(1)
        ->and($data['installed'][0]['composer_name'])->toBe('capell-app/seo-suite')
        ->and($data['installed'][0]['status'])->toBe('active');
});

it('uses conservative empty defaults without commercial heartbeat data', function (): void {
    resolve(MarketplaceInstanceResolver::class)->forget();

    expect(BuildMarketplacePurchasesPageDataAction::run())->toMatchArray([
        'purchases' => [],
        'installed' => [],
        'renewal_url' => null,
        'support_url' => null,
        'membership' => null,
        'membership_price' => null,
        'membership_renewal_price' => null,
        'priority_support_price' => null,
        'currency' => null,
    ]);
});

it('does not let malformed purchase dates break the page data contract', function (): void {
    MarketplaceInstance::query()->create([
        'instance_id' => '018f47a2-62da-7ca4-b732-bd3c715db1c0',
        'signing_secret_encrypted' => 'test-signing-secret',
        'connection_metadata' => [
            'commercial' => [
                'purchases' => [
                    [
                        'name' => 'Broken Date Suite',
                        'status' => 'active',
                        'access_ends_at' => 'not-a-date',
                    ],
                ],
            ],
        ],
        'last_heartbeat_at' => now(),
    ]);
    resolve(MarketplaceInstanceResolver::class)->forget();

    expect(BuildMarketplacePurchasesPageDataAction::run()['purchases'])->toBe([
        [
            'name' => 'Broken Date Suite',
            'status' => 'active',
            'access_ends_at' => null,
        ],
    ]);
});

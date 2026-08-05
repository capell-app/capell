<?php

declare(strict_types=1);

use Capell\Marketplace\Enums\MarketplacePermission;
use Capell\Marketplace\Filament\Pages\MarketplacePurchasesPage;
use Capell\Marketplace\Models\MarketplaceInstance;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(CreatesAdminUser::class);

it('renders heartbeat purchases membership pricing and renewal links', function (): void {
    Permission::findOrCreate(MarketplacePermission::ViewMarketplacePage->value, 'web');
    test()->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo(MarketplacePermission::ViewMarketplacePage->value);
    MarketplaceInstance::query()->create([
        'instance_id' => '00000000-0000-4000-8000-000000000123',
        'signing_secret_encrypted' => 'test-secret',
        'connection_metadata' => [
            'commercial' => [
                'purchases' => [[
                    'name' => 'SEO Suite',
                    'status' => 'active',
                    'access_ends_at' => '2027-08-05T00:00:00Z',
                ]],
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
                'renewal_url' => 'https://marketplace.example.test/account/renew',
                'support_url' => 'https://marketplace.example.test/support',
            ],
        ],
        'last_heartbeat_at' => now(),
    ]);

    Livewire::test(MarketplacePurchasesPage::class)
        ->assertSuccessful()
        ->assertSee('data-capell-marketplace-purchases', false)
        ->assertSee('data-capell-marketplace-membership-comparison', false)
        ->assertSee('SEO Suite')
        ->assertSee('Capell Membership')
        ->assertSee('£199.00')
        ->assertSee('£159.20')
        ->assertSee('£49.00')
        ->assertSee('https://marketplace.example.test/account/renew', false)
        ->assertSee('https://marketplace.example.test/support', false);
});

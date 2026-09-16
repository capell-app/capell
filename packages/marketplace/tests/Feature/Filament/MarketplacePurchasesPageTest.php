<?php

declare(strict_types=1);

use Capell\Marketplace\Enums\MarketplacePermission;
use Capell\Marketplace\Filament\Pages\MarketplacePurchasesPage;
use Capell\Marketplace\Models\MarketplaceInstance;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(CreatesAdminUser::class);

it('renders heartbeat purchases and renewal links', function (): void {
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
                'currency' => 'GBP',
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
        ->assertSee('SEO Suite')
        ->assertSee('£49.00')
        ->assertSee('https://marketplace.example.test/account/renew', false)
        ->assertSee('https://marketplace.example.test/support', false);
});

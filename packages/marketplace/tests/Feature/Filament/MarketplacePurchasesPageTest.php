<?php

declare(strict_types=1);

use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\Core\Contracts\Marketplace\ExtensionEntitlements;
use Capell\Core\Data\Marketplace\ExtensionLicenceDecisionData;
use Capell\Core\Enums\ExtensionStatusEnum;
use Capell\Core\Models\CapellExtension;
use Capell\Marketplace\Actions\RunMarketplaceHeartbeatAction;
use Capell\Marketplace\Data\PhoneHomeResultData;
use Capell\Marketplace\Enums\MarketplacePermission;
use Capell\Marketplace\Filament\Pages\MarketplacePurchasesPage;
use Capell\Marketplace\Models\MarketplaceInstance;
use Capell\Marketplace\Support\MarketplaceInstanceResolver;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Sinnbeck\DomAssertions\Asserts\AssertElement;
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

it('distinguishes unavailable account snapshots from a known empty purchase list', function (string $state): void {
    Permission::findOrCreate(MarketplacePermission::ViewMarketplacePage->value, 'web');
    test()->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo(MarketplacePermission::ViewMarketplacePage->value);
    if ($state !== 'not_connected') {
        MarketplaceInstance::query()->create([
            'instance_id' => '00000000-0000-4000-8000-000000000124',
            'signing_secret_encrypted' => 'test-secret',
            'connection_metadata' => $state === 'current' ? ['commercial' => ['purchases' => []]] : [],
            'last_heartbeat_at' => $state === 'current' ? now() : null,
        ]);
    }

    resolve(MarketplaceInstanceResolver::class)->forget();

    Livewire::test(MarketplacePurchasesPage::class)
        ->assertSee('data-capell-account-snapshot="' . $state . '"', false);
})->with(['not_connected', 'not_observed', 'current']);

it('retains dated purchases after a failed authorised refresh', function (): void {
    test()->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo(Permission::findOrCreate(MarketplacePermission::ViewMarketplacePage->value, 'web'));
    test()->authenticatedUser()->givePermissionTo(Permission::findOrCreate(ExtensionsPage::MANAGE_PERMISSION, 'web'));
    MarketplaceInstance::query()->create([
        'instance_id' => '00000000-0000-4000-8000-000000000125',
        'signing_secret_encrypted' => 'test-secret',
        'connection_metadata' => ['commercial' => ['purchases' => [['name' => 'Retained purchase']]]],
        'last_heartbeat_at' => now()->subDay(),
    ]);
    resolve(MarketplaceInstanceResolver::class)->forget();
    bindFakeAction(RunMarketplaceHeartbeatAction::class, new PhoneHomeResultData(successful: false));
    Livewire::test(MarketplacePurchasesPage::class)->callAction('refreshPurchases')
        ->assertSee('data-capell-account-snapshot="retained"', false)->assertSee('Retained purchase')
        ->assertSee(now()->subDay()->toDateString());
});

it('does not grant purchase refresh authority to a browse-only actor', function (): void {
    test()->actingAsUser();
    test()->authenticatedUser()->givePermissionTo(Permission::findOrCreate(MarketplacePermission::ViewMarketplacePage->value, 'web'));
    $spy = bindFakeAction(RunMarketplaceHeartbeatAction::class, new PhoneHomeResultData(successful: true));
    Livewire::test(MarketplacePurchasesPage::class)->assertActionHidden('refreshPurchases')->assertActionHidden('connectMarketplaceAccount');
    expect($spy->called)->toBeFalse();
});

it('pairs the displayed licence status with its successful or retained observation time', function (bool $refreshFails): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-25T12:34:56Z'));
    $storedTime = CarbonImmutable::parse('2026-10-01T09:00:00Z');
    $observationTime = now()->toAtomString();
    test()->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo(Permission::findOrCreate(MarketplacePermission::ViewMarketplacePage->value, 'web'));
    CapellExtension::query()->create([
        'composer_name' => 'test/dated-licence',
        'name' => 'Dated licence',
        'version' => '1.0.0',
        'status' => ExtensionStatusEnum::Enabled,
        'is_paid_marketplace_extension' => true,
        'marketplace_runtime_status' => 'active',
        'marketplace_activation_checked_at' => $storedTime,
    ]);
    $entitlements = Mockery::mock(ExtensionEntitlements::class);
    if ($refreshFails) {
        $entitlements->shouldReceive('licenceDecision')->andThrow(new RuntimeException('Account service unavailable'));
    } else {
        $entitlements->shouldReceive('licenceDecision')->andReturn(ExtensionLicenceDecisionData::fromApiResponse(['licence_status' => 'expired']));
    }

    app()->instance(ExtensionEntitlements::class, $entitlements);

    Livewire::test(MarketplacePurchasesPage::class)
        ->assertSuccessful()
        ->assertElementExists('[data-capell-marketplace-licence]', function (AssertElement $article) use ($refreshFails, $storedTime, $observationTime): void {
            $article->containsText(__('capell-marketplace::marketplace.purchases.status', ['status' => $refreshFails ? 'active' : 'expired']));
            $article->containsText(__('capell-marketplace::marketplace.purchases.observed', ['date' => $refreshFails ? $storedTime->toAtomString() : $observationTime]));
            $article->doesntContainText($refreshFails ? $observationTime : $storedTime->toAtomString());
            if ($refreshFails) {
                $article->containsText(__('capell-marketplace::marketplace.purchases.licence_retained'));
            } else {
                $article->doesntContainText(__('capell-marketplace::marketplace.purchases.licence_retained'));
            }
        });
})->with(['successful decision' => false, 'failed decision' => true]);

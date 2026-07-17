<?php

declare(strict_types=1);

use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\Core\Models\SiteDomain;
use Capell\Marketplace\Enums\MarketplaceConnectionMode;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Filament\Actions\MarketplaceConnectionFormModel;
use Capell\Marketplace\Filament\Actions\OpenMarketplaceAction;
use Capell\Marketplace\Filament\Pages\MarketplacePage;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Capell\Marketplace\Models\MarketplaceInstance;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(CreatesAdminUser::class);

function grantInstalledExtensionsPageAccess(): void
{
    Permission::create(['name' => 'View:ExtensionsPage', 'guard_name' => 'web']);
    test()->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo('View:ExtensionsPage');
}

function grantInstalledExtensionsPageManagementAccessForMarketplaceTest(): void
{
    grantInstalledExtensionsPageAccess();

    Permission::create(['name' => ExtensionsPage::MANAGE_PERMISSION, 'guard_name' => 'web']);
    test()->authenticatedUser()->givePermissionTo(ExtensionsPage::MANAGE_PERMISSION);
}

function marketplaceExtensionsPageAttempt(array $overrides = []): MarketplaceInstallAttempt
{
    return MarketplaceInstallAttempt::query()->create([
        'composer_name' => 'capell-app/seo-suite',
        'extension_slug' => 'seo-suite',
        'extension_name' => 'SEO Suite',
        'kind' => 'tool',
        'status' => MarketplaceInstallIntentStatus::Queued,
        'composer_command' => 'composer require capell-app/seo-suite:^2.1',
        'version_constraint' => '^2.1',
        'queued_at' => now(),
        ...$overrides,
    ]);
}

it('shows marketplace header actions without the connection alert on the installed extensions page', function (): void {
    grantInstalledExtensionsPageManagementAccessForMarketplaceTest();

    config(['capell-marketplace.marketplace.base_url' => 'https://marketplace.test/api']);

    Livewire::test(ExtensionsPage::class)
        ->assertSuccessful()
        ->assertActionDoesNotExist('browseMarketplace')
        ->assertActionVisible('openMarketplace')
        ->assertActionHidden('marketplaceInstallOperations')
        ->assertActionExists('connectMarketplaceAccount')
        ->assertSee(__('capell-marketplace::marketplace.marketplace.connect_account_button'))
        ->assertSee(__('capell-marketplace::marketplace.marketplace.extensions_marketplace'))
        ->assertDontSee(__('capell-marketplace::marketplace.marketplace.status.not_connected.title'))
        ->assertDontSee(__('capell-marketplace::marketplace.marketplace.status.not_connected.body'))
        ->assertDontSee(__('capell-marketplace::marketplace.marketplace.verify_button'));
});

it('shows the marketplace install operations header action only when operations exist', function (): void {
    grantInstalledExtensionsPageManagementAccessForMarketplaceTest();

    marketplaceExtensionsPageAttempt(['status' => MarketplaceInstallIntentStatus::Queued]);

    Livewire::test(ExtensionsPage::class)
        ->assertSuccessful()
        ->assertActionVisible('marketplaceInstallOperations');
});

it('keeps marketplace separate while grouping account and dashboard actions in the extensions header', function (): void {
    grantInstalledExtensionsPageManagementAccessForMarketplaceTest();

    Permission::create(['name' => 'View:SettingsPage', 'guard_name' => 'web']);
    test()->authenticatedUser()->givePermissionTo('View:SettingsPage');

    config([
        'capell-marketplace.marketplace.base_url' => 'https://marketplace.test/api',
        'capell-marketplace.marketplace.web_url' => 'https://capell.test',
    ]);

    Livewire::test(ExtensionsPage::class)
        ->assertSuccessful()
        ->assertActionVisible('openMarketplace')
        ->assertActionExists('connectMarketplaceAccount')
        ->assertActionHasUrl('createMarketplaceAccount', 'https://capell.test/register')
        ->assertActionExists('customiseExtensionsDashboard');
});

it('opens the extensions marketplace as a slide-over with modal context', function (): void {
    $action = OpenMarketplaceAction::make(resolve(MarketplaceConnectionFormModel::class));

    expect($action->isModalSlideOver())->toBeTrue()
        ->and($action->getModalWidth())->toBe(Width::ScreenExtraLarge)
        ->and($action->getModalHeading())->toBe(__('capell-marketplace::marketplace.marketplace.extensions_marketplace'))
        ->and($action->getModalDescription())->toBe(__('capell-marketplace::marketplace.explorer.description'));
});

it('renders the marketplace selection footer above card overlays', function (): void {
    expect(view('capell-marketplace::filament.actions.open-marketplace-footer')->render())
        ->toContain('id="capell-marketplace-browser-modal-footer"')
        ->toContain('relative z-50 w-full');
});

it('shows the installed extensions marketplace action after account connection is fully ready', function (): void {
    grantInstalledExtensionsPageManagementAccessForMarketplaceTest();

    config(['capell-marketplace.marketplace.base_url' => 'https://marketplace.test/api']);

    MarketplaceInstance::query()->create([
        'instance_id' => 'instance-connected-open-marketplace',
        'signing_secret_encrypted' => 'secret-value',
        'connection_mode' => MarketplaceConnectionMode::AccountLinked,
        'account_id' => 'acct_123',
        'account_email' => 'ben@example.com',
        'account_email_verified_at' => now(),
        'last_heartbeat_at' => now(),
    ]);

    Livewire::test(ExtensionsPage::class)
        ->assertSuccessful()
        ->assertActionVisible('openMarketplace')
        ->assertSee(__('capell-marketplace::marketplace.marketplace.extensions_marketplace'))
        ->assertDontSee(__('capell-marketplace::marketplace.marketplace.connect_account_button'));
});

it('shows the installed extensions marketplace alert without manage buttons to view-only users', function (): void {
    grantInstalledExtensionsPageAccess();

    config(['capell-marketplace.marketplace.base_url' => 'https://marketplace.test/api']);

    Livewire::test(ExtensionsPage::class)
        ->assertSuccessful()
        ->assertDontSee(__('capell-marketplace::marketplace.marketplace.connect_account_button'))
        ->assertDontSee(__('capell-marketplace::marketplace.marketplace.create_challenge_button'))
        ->assertDontSee(__('capell-marketplace::marketplace.marketplace.approve_verify_button'));
});

it('keeps marketplace connection state package-owned', function (): void {
    expect(resolve(MarketplaceConnectionFormModel::class)->connectionState())->toBe('not_connected');
});

it('does not lead account-linked setup back into local domain validation', function (): void {
    grantInstalledExtensionsPageManagementAccessForMarketplaceTest();

    config(['capell-marketplace.marketplace.base_url' => 'https://marketplace.test/api']);

    SiteDomain::factory()->createOne(['domain' => 'example.com']);

    MarketplaceInstance::query()->create([
        'instance_id' => 'instance-notification-domain-validation',
        'signing_secret_encrypted' => 'secret-value',
        'connection_mode' => MarketplaceConnectionMode::AccountLinked,
        'account_id' => 'acct_123',
        'last_heartbeat_at' => now(),
    ]);

    $content = view('capell-marketplace::filament.pages.extensions-page-marketplace-status', [
        'marketplaceConnection' => resolve(MarketplaceConnectionFormModel::class),
        'marketplaceConnectionActionsVisible' => true,
        'marketplaceConnectionButtonsVisible' => true,
        'marketplaceConnectionDetailsVisible' => true,
    ])->render();

    expect($content)
        ->toContain(__('capell-marketplace::marketplace.marketplace.status.connected.title'))
        ->not->toContain(__('capell-marketplace::marketplace.marketplace.create_challenge_button'));
});

it('auto-opens marketplace from the installed extensions page after account callback', function (): void {
    grantInstalledExtensionsPageManagementAccessForMarketplaceTest();

    config(['capell-marketplace.marketplace.base_url' => 'https://marketplace.test/api']);

    SiteDomain::factory()->createOne(['domain' => 'example.com']);

    MarketplaceInstance::query()->create([
        'instance_id' => 'instance-account-callback',
        'signing_secret_encrypted' => 'secret-value',
        'connection_mode' => MarketplaceConnectionMode::AccountLinked,
        'account_id' => 'acct_123',
        'account_email' => 'ben@example.com',
        'account_email_verified_at' => now(),
        'last_heartbeat_at' => now(),
    ]);

    session()->flash('capell-marketplace.open-marketplace', true);

    Livewire::test(ExtensionsPage::class)
        ->assertSuccessful()
        ->assertSeeHtml("mountAction('openMarketplace')")
        ->assertSeeHtml("mountAction('marketplaceInstallOperations')")
        ->assertSee(__('capell-marketplace::marketplace.marketplace.extensions_marketplace'))
        ->assertDontSee(__('capell-marketplace::marketplace.marketplace.connect_account_button'));
});

it('can mount the installed extensions marketplace action after account connection', function (): void {
    grantInstalledExtensionsPageManagementAccessForMarketplaceTest();

    config(['capell-marketplace.marketplace.base_url' => 'https://marketplace.test/api']);

    Http::fake([
        'https://marketplace.test/api/extensions*' => Http::response([
            'data' => [],
            'links' => ['next' => null],
        ]),
    ]);

    SiteDomain::factory()->createOne(['domain' => 'example.com']);

    MarketplaceInstance::query()->create([
        'instance_id' => 'instance-mount-domain-validation',
        'signing_secret_encrypted' => 'secret-value',
        'connection_mode' => MarketplaceConnectionMode::AccountLinked,
        'account_id' => 'acct_123',
        'last_heartbeat_at' => now(),
    ]);

    Livewire::test(ExtensionsPage::class)
        ->assertActionVisible('openMarketplace')
        ->mountAction('openMarketplace')
        ->assertSee(__('capell-marketplace::marketplace.marketplace.extensions_marketplace'))
        ->assertMountedActionModalSee(__('capell-marketplace::marketplace.marketplace.extensions_marketplace'))
        ->assertMountedActionModalDontSee('capell-marketplace::generic.extensions_marketplace')
        ->assertMountedActionModalSee(__('capell-marketplace::marketplace.filters.loading_heading'));
});

it('renders the marketplace page through the same marketplace browser backend', function (): void {
    grantInstalledExtensionsPageManagementAccessForMarketplaceTest();

    config(['capell-marketplace.marketplace.base_url' => 'https://marketplace.test/api']);

    Http::fake([
        'https://marketplace.test/api/extensions*' => Http::response([
            'data' => [],
            'links' => ['next' => null],
        ]),
    ]);

    Livewire::test(MarketplacePage::class)
        ->assertSuccessful()
        ->assertSee(__('capell-marketplace::marketplace.page.heading'))
        ->assertSee(__('capell-marketplace::marketplace.filters.loading_heading'));
});

it('serves the marketplace page over the admin route', function (): void {
    $this->withoutVite();

    grantInstalledExtensionsPageManagementAccessForMarketplaceTest();

    config(['capell-marketplace.marketplace.base_url' => 'https://marketplace.test/api']);

    Http::fake([
        'https://marketplace.test/api/extensions*' => Http::response([
            'data' => [],
            'links' => ['next' => null],
        ]),
    ]);

    $this->get(MarketplacePage::getUrl())
        ->assertSuccessful()
        ->assertSee('id="capell-marketplace-browser-modal-footer"', false)
        ->assertSee(__('capell-marketplace::marketplace.page.heading'));
});

it('opens marketplace from an installed extensions search', function (): void {
    grantInstalledExtensionsPageManagementAccessForMarketplaceTest();

    config(['capell-marketplace.marketplace.base_url' => 'https://marketplace.test/api']);

    Http::fake([
        'https://marketplace.test/api/extensions*' => Http::response([
            'data' => [],
            'links' => ['next' => null],
        ]),
    ]);

    Livewire::test(ExtensionsPage::class)
        ->set('tableFilters.extension_filters.search', 'seo suite')
        ->mountAction(OpenMarketplaceAction::name())
        ->assertMountedActionModalSee(__('capell-marketplace::marketplace.marketplace.extensions_marketplace'));
});

it('opens marketplace from the installed extensions empty state action with the filter search', function (): void {
    grantInstalledExtensionsPageManagementAccessForMarketplaceTest();

    config(['capell-marketplace.marketplace.base_url' => 'https://marketplace.test/api']);

    Http::fake([
        'https://marketplace.test/api/extensions*' => Http::response([
            'data' => [],
            'links' => ['next' => null],
        ]),
    ]);

    Livewire::test(ExtensionsPage::class)
        ->set('tableFilters.extension_filters.search', 'seo suite')
        ->mountTableAction(OpenMarketplaceAction::name())
        ->assertMountedActionModalSee(__('capell-marketplace::marketplace.marketplace.extensions_marketplace'));
});

it('redirects extension managers to the Capell account approval URL from the installed extensions alert', function (): void {
    grantInstalledExtensionsPageManagementAccessForMarketplaceTest();

    config([
        'app.url' => 'http://capell-ruby.test',
        'capell-marketplace.marketplace.base_url' => 'https://capell.test/api/v1',
    ]);

    Http::fake([
        'https://capell.test/api/v1/marketplace/connections' => Http::response([
            'data' => [
                'connection_session_id' => 'mcs_123',
                'approval_url' => 'https://capell.test/marketplace/connect/mcs_123',
                'expires_at' => now()->addMinutes(10)->toIso8601String(),
            ],
        ], 201),
    ]);

    Livewire::test(ExtensionsPage::class)
        ->mountAction('connectMarketplaceAccount')
        ->assertRedirect('https://capell.test/marketplace/connect/mcs_123');
});

it('redirects extension managers to Capell App login when the connection session cannot start', function (): void {
    grantInstalledExtensionsPageManagementAccessForMarketplaceTest();

    config([
        'app.url' => 'http://capell-ruby.test',
        'capell-marketplace.marketplace.base_url' => 'https://capell.test/api/v1',
        'capell-marketplace.marketplace.web_url' => 'https://capell.test',
    ]);

    Http::fake([
        'https://capell.test/api/v1/marketplace/connections' => Http::response([
            'message' => 'Marketplace unavailable.',
        ], 503),
    ]);

    Livewire::test(ExtensionsPage::class)
        ->mountAction('connectMarketplaceAccount')
        ->assertRedirect('https://capell.test/login');
});

it('does not allow view-only extensions users to mount connection actions', function (): void {
    grantInstalledExtensionsPageAccess();

    config(['capell-marketplace.marketplace.base_url' => 'https://marketplace.test/api']);

    Livewire::test(ExtensionsPage::class)
        ->assertActionHidden('connectMarketplaceAccount')
        ->mountAction('connectMarketplaceAccount')
        ->assertSet('mountedActions', []);
});

it('hides marketplace connection details from view-only status cards', function (): void {
    MarketplaceInstance::query()->create([
        'instance_id' => '00000000-0000-4000-8000-000000000123',
        'signing_secret_encrypted' => 'secret-value',
        'connection_mode' => MarketplaceConnectionMode::AccountLinked,
        'account_id' => 'acct_123',
        'account_email' => 'ben@example.com',
        'last_heartbeat_at' => now(),
    ]);

    $content = view('capell-marketplace::filament.pages.extensions-page-marketplace-status', [
        'marketplaceConnection' => resolve(MarketplaceConnectionFormModel::class),
        'marketplaceConnectionActionsVisible' => false,
    ])->render();

    expect($content)
        ->toContain(__('capell-marketplace::marketplace.marketplace.status.connected.view_only_body'))
        ->not->toContain('ben@example.com')
        ->not->toContain('00000000-0000-4000-8000-000000000123');
});

it('renders safe commercial status when connection details are explicitly available', function (): void {
    MarketplaceInstance::query()->create([
        'instance_id' => 'instance-commercial-status',
        'signing_secret_encrypted' => 'secret-value',
        'connection_mode' => MarketplaceConnectionMode::AccountLinked,
        'account_id' => 'acct_123',
        'connection_metadata' => [
            'commercial' => [
                'purchases' => [[
                    'name' => 'Capell Membership',
                    'status' => 'active',
                    'access_ends_at' => '2027-07-16T00:00:00+00:00',
                    'protected_updates' => true,
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
                'renewal_url' => 'https://capell.test/customer/packages',
                'support_url' => 'https://capell.test/support/request',
                'priority_support_price_cents' => 4900,
                'expired_explanation' => 'Installed software continues to run after expiry; protected updates and included support require renewal.',
            ],
        ],
        'last_heartbeat_at' => now(),
    ]);

    $content = view('capell-marketplace::filament.pages.extensions-page-marketplace-status', [
        'marketplaceConnection' => resolve(MarketplaceConnectionFormModel::class),
        'marketplaceConnectionActionsVisible' => true,
        'marketplaceConnectionDetailsVisible' => true,
    ])->render();

    expect($content)
        ->toContain('Capell Membership')
        ->toContain('GBP 199.00')
        ->toContain('GBP 159.20')
        ->toContain('38 products')
        ->toContain('£49.00')
        ->toContain('https://capell.test/customer/packages')
        ->toContain('https://capell.test/support/request')
        ->not->toContain('secret-value')
        ->not->toContain('acct_123');
});

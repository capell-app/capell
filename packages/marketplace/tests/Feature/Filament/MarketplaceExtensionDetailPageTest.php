<?php

declare(strict_types=1);

use Capell\Core\Facades\CapellCore;
use Capell\Marketplace\Enums\MarketplaceConnectionMode;
use Capell\Marketplace\Enums\MarketplaceInstallCapability;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Enums\MarketplacePermission;
use Capell\Marketplace\Filament\Pages\MarketplaceExtensionDetailPage;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Capell\Marketplace\Models\MarketplaceInstance;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(CreatesAdminUser::class);

it('renders marketplace extension details with health alerts and accessible feedback controls', function (): void {
    Permission::findOrCreate(MarketplacePermission::ViewMarketplacePage->value, 'web');
    test()->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo(MarketplacePermission::ViewMarketplacePage->value);

    config([
        'capell-marketplace.marketplace.base_url' => 'https://marketplace.test/api',
        'capell-marketplace.marketplace.web_url' => 'https://marketplace.test',
    ]);

    Http::fake([
        'https://marketplace.test/api/extensions/seo-suite' => marketplaceDetailResponse(),
    ]);

    Livewire::test(MarketplaceExtensionDetailPage::class, ['slug' => 'seo-suite'])
        ->assertSuccessful()
        ->assertSee('Advanced SEO Suite')
        ->assertSee('Can I install?')
        ->assertSee('Yes')
        ->assertSee('What next?')
        ->assertSee('Install from Marketplace')
        ->assertSee('Screenshots')
        ->assertSee('5 screenshots')
        ->assertSee('data-capell-marketplace-extension-screenshots', false)
        ->assertSee('data-capell-marketplace-extension-docs', false)
        ->assertSee('data-capell-marketplace-suite="growth"', false)
        ->assertSee('data-capell-marketplace-suite-member="capell-app/html-cache"', false)
        ->assertSee('data-capell-marketplace-trial', false)
        ->assertSee('Admin overview')
        ->assertSee('Frontend output')
        ->assertSee('loading="lazy"', false)
        ->assertSee('https://marketplace.test/docs/seo-suite')
        ->assertSee(__('capell-marketplace::marketplace.detail.docs_link_cta'))
        ->assertSee('Premium')
        ->assertSee('First Party')
        ->assertSee('Priority')
        ->assertSee('Admin')
        ->assertSee('Frontend')
        ->assertSee('capell-app/html-cache')
        ->assertSee('15 ms')
        ->assertSee('3 contributions')
        ->assertSee(__('capell-marketplace::marketplace.detail.download_available'))
        ->assertSee(__('capell-marketplace::marketplace.detail.install_available'))
        ->assertSee(__('capell-marketplace::marketplace.detail.manual_install_checkbox_label'))
        ->assertDontSee('composer require capell-app/seo-suite:^2.1.0')
        ->assertDontSee('php artisan capell:extension-install capell-app/seo-suite')
        ->assertSee('https://marketplace.test/extensions/seo-suite')
        ->assertSee('target="_blank"', false)
        ->assertSee('rel="noopener noreferrer"', false)
        ->set('showManualInstallCommands', true)
        ->assertSee('data-capell-marketplace-manual-install-commands', false)
        ->assertSee('composer require capell-app/seo-suite:^2.1.0')
        ->assertSee('php artisan capell:extension-install capell-app/seo-suite')
        ->set('feedbackStatus', 'pending')
        ->assertSee('aria-live="polite"', false)
        ->assertSee('aria-invalid', false);

    Livewire::test(MarketplaceExtensionDetailPage::class, ['slug' => 'seo-suite'])
        ->call('submitFeedback')
        ->assertHasErrors(['feedbackRating'])
        ->set('feedbackComment', '   ')
        ->set('feedbackTip', '   ')
        ->call('submitFeedback')
        ->assertHasErrors(['feedbackRating'])
        ->set('feedbackRating', 6)
        ->call('submitFeedback')
        ->assertHasErrors(['feedbackRating'])
        ->assertSee('feedback-rating-error', false);
});

it('renders activation-required licence input and keeps server failures as field validation', function (): void {
    Permission::findOrCreate(MarketplacePermission::ViewMarketplacePage->value, 'web');
    test()->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo(MarketplacePermission::ViewMarketplacePage->value);
    MarketplaceInstance::query()->create([
        'instance_id' => '00000000-0000-4000-8000-000000000123',
        'signing_secret_encrypted' => 'test-secret',
        'connection_mode' => MarketplaceConnectionMode::AccountLinked,
        'account_id' => 'acct_123',
        'account_email_verified_at' => now(),
        'last_heartbeat_at' => now(),
    ]);

    config([
        'app.url' => 'https://example.test',
        'capell-marketplace.marketplace.base_url' => 'https://marketplace.test/api',
    ]);

    Http::fake([
        'https://marketplace.test/api/extensions/by-composer*' => Http::response([
            'data' => [[
                'slug' => 'activation-suite',
                'name' => 'Activation Suite',
                'composer_name' => 'capell-app/activation-suite',
                'kind' => 'plugin',
                'is_paid' => true,
                'price_cents' => 4900,
                'install_eligibility' => [
                    'state' => 'activation_required',
                ],
            ]],
        ]),
        'https://marketplace.test/api/extensions/activation-suite' => Http::response([
            'data' => [
                'slug' => 'activation-suite',
                'name' => 'Activation Suite',
                'composer_name' => 'capell-app/activation-suite',
                'kind' => 'plugin',
                'is_paid' => true,
                'price_cents' => 4900,
                'install_eligibility' => [
                    'state' => 'activation_required',
                ],
                'licence' => [
                    'licence_status' => 'purchased',
                    'can_install' => false,
                ],
            ],
        ]),
        'https://marketplace.test/api/extensions/activation-suite/install-authorization' => Http::response([
            'message' => 'Private server failure detail.',
        ], 422),
    ]);

    Livewire::test(MarketplaceExtensionDetailPage::class, ['slug' => 'activation-suite'])
        ->assertSee('data-capell-marketplace-licence-form', false)
        ->set('licenseKey', 'invalid-key')
        ->call('activateLicence')
        ->assertHasErrors(['licenseKey'])
        ->assertSee(__('capell-marketplace::marketplace.install.license_key_invalid'))
        ->assertDontSee('Private server failure detail.');
});

it('marks rating-only feedback as required in the rendered controls', function (): void {
    Permission::findOrCreate(MarketplacePermission::ViewMarketplacePage->value, 'web');
    test()->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo(MarketplacePermission::ViewMarketplacePage->value);

    config(['capell-marketplace.marketplace.base_url' => 'https://marketplace.test/api']);

    Http::fake([
        'https://marketplace.test/api/extensions/seo-suite' => marketplaceDetailResponse(canComment: false, canRate: true),
    ]);

    Livewire::test(MarketplaceExtensionDetailPage::class, ['slug' => 'seo-suite'])
        ->assertSuccessful()
        ->assertSee(__('capell-marketplace::marketplace.feedback.required_suffix'))
        ->assertSee('aria-required="true"', false)
        ->assertSee('required', false);
});

it('requires comment feedback when rating is unavailable', function (): void {
    Permission::findOrCreate(MarketplacePermission::ViewMarketplacePage->value, 'web');
    test()->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo(MarketplacePermission::ViewMarketplacePage->value);

    config(['capell-marketplace.marketplace.base_url' => 'https://marketplace.test/api']);

    Http::fake([
        'https://marketplace.test/api/extensions/seo-suite' => marketplaceDetailResponse(canComment: true, canRate: false),
    ]);

    Livewire::test(MarketplaceExtensionDetailPage::class, ['slug' => 'seo-suite'])
        ->call('submitFeedback')
        ->assertHasErrors(['feedbackComment'])
        ->set('feedbackTip', '   ')
        ->call('submitFeedback')
        ->assertHasErrors(['feedbackComment']);
});

it('shows marketplace detail outages without treating them as not found', function (): void {
    Permission::findOrCreate(MarketplacePermission::ViewMarketplacePage->value, 'web');
    test()->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo(MarketplacePermission::ViewMarketplacePage->value);

    config(['capell-marketplace.marketplace.base_url' => 'https://marketplace.test/api']);

    Http::fake([
        'https://marketplace.test/api/extensions/seo-suite' => Http::response([
            'message' => 'Marketplace maintenance window.',
        ], 503),
    ]);

    Livewire::test(MarketplaceExtensionDetailPage::class, ['slug' => 'seo-suite'])
        ->assertSuccessful()
        ->assertSee(__('capell-marketplace::marketplace.detail.unavailable_heading'))
        ->assertSee(__('capell-marketplace::marketplace.errors.operator_action_failed'))
        ->assertDontSee('Marketplace maintenance window.');
});

it('uses the marketplace page permission for detail page access', function (): void {
    Permission::findOrCreate(MarketplacePermission::ViewMarketplacePage->value, 'web');
    test()->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo(MarketplacePermission::ViewMarketplacePage->value);

    expect(MarketplaceExtensionDetailPage::canAccess())->toBeTrue();
});

function marketplaceDetailResponse(bool $canComment = true, bool $canRate = true): mixed
{
    return Http::response([
        'data' => [
            'slug' => 'seo-suite',
            'name' => 'Advanced SEO Suite',
            'composer_name' => 'capell-app/seo-suite',
            'kind' => 'plugin',
            'description' => 'SEO tools for Capell.',
            'latest_version' => '2.1.0',
            'documentation_url' => 'https://marketplace.test/docs/seo-suite',
            'price_cents' => 4900,
            'is_paid' => true,
            'images' => [
                [
                    'url' => 'https://cdn.marketplace.test/seo-suite/admin-overview.png',
                    'alt' => 'SEO Suite admin overview',
                    'caption' => 'Admin overview',
                ],
                [
                    'url' => 'https://cdn.marketplace.test/seo-suite/frontend-output.png',
                    'alt' => 'SEO Suite frontend output',
                    'caption' => 'Frontend output',
                ],
                [
                    'url' => 'https://cdn.marketplace.test/seo-suite/settings.png',
                    'alt' => 'SEO Suite settings',
                    'caption' => 'Settings',
                ],
                [
                    'url' => 'https://cdn.marketplace.test/seo-suite/checks.png',
                    'alt' => 'SEO Suite checks',
                    'caption' => 'Checks',
                ],
                [
                    'url' => 'https://cdn.marketplace.test/seo-suite/reporting.png',
                    'alt' => 'SEO Suite reporting',
                    'caption' => 'Reporting',
                ],
            ],
            'display_name' => 'Advanced SEO Suite',
            'product' => ['group' => 'Marketing', 'tier' => 'premium', 'bundle' => 'growth'],
            'commercial' => ['requestedCertification' => 'first-party', 'supportPolicy' => 'priority'],
            'trial' => [
                'label' => 'Try Advanced SEO Suite',
                'duration_days' => 14,
                'description' => 'Full access during the trial.',
            ],
            'surfaces' => ['admin', 'frontend'],
            'dependencies' => ['requires' => ['capell-app/html-cache']],
            'performance' => ['frontendRenderBudgetMs' => 15],
            'contribution_summary' => ['admin-page' => 1, 'frontend-component' => 2],
            'install_eligibility' => 'allowed',
            'next_action' => 'Install from Marketplace',
            'health_status' => 'ok',
            'private_docs_entitled' => true,
            'licence' => [
                'licence_status' => 'active',
                'can_comment' => $canComment,
                'can_rate' => $canRate,
                'can_download' => true,
                'can_install' => true,
            ],
        ],
    ]);
}

it('promotes the install instructions to the primary action on a manual-only host', function (): void {
    Permission::findOrCreate(MarketplacePermission::ViewMarketplacePage->value, 'web');
    test()->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo(MarketplacePermission::ViewMarketplacePage->value);

    config([
        'capell-marketplace.marketplace.base_url' => 'https://marketplace.test/api',
        'capell-marketplace.marketplace.web_url' => 'https://marketplace.test',
    ]);

    Http::fake([
        'https://marketplace.test/api/extensions/seo-suite' => marketplaceDetailResponse(),
    ]);

    fakeMarketplaceEnvironmentReadiness(capability: MarketplaceInstallCapability::ManualOnly);

    // A manual-only host keeps a fully browsable catalogue; what changes is that
    // running the commands becomes the action, not a hidden disclosure.
    Livewire::test(MarketplaceExtensionDetailPage::class, ['slug' => 'seo-suite'])
        ->assertSuccessful()
        ->assertSeeHtml('data-capell-marketplace-manual-install-cta')
        ->assertSee(__('capell-marketplace::marketplace.readiness.manual_install_cta_button'))
        ->assertDontSee('composer require capell-app/seo-suite:^2.1.0')
        ->call('showManualInstallInstructions')
        ->assertSet('showManualInstallCommands', true)
        ->assertSee('composer require capell-app/seo-suite:^2.1.0');
});

it('keeps the install instructions a disclosure on a host that installs automatically', function (): void {
    Permission::findOrCreate(MarketplacePermission::ViewMarketplacePage->value, 'web');
    test()->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo(MarketplacePermission::ViewMarketplacePage->value);

    config([
        'capell-marketplace.marketplace.base_url' => 'https://marketplace.test/api',
        'capell-marketplace.marketplace.web_url' => 'https://marketplace.test',
    ]);

    Http::fake([
        'https://marketplace.test/api/extensions/seo-suite' => marketplaceDetailResponse(),
    ]);

    fakeMarketplaceEnvironmentReadiness(capability: MarketplaceInstallCapability::Automated);

    Livewire::test(MarketplaceExtensionDetailPage::class, ['slug' => 'seo-suite'])
        ->assertSuccessful()
        ->assertDontSeeHtml('data-capell-marketplace-manual-install-cta')
        ->assertSee(__('capell-marketplace::marketplace.detail.manual_install_checkbox_label'));
});

it('offers no update while the extension already has an operation in flight', function (): void {
    Permission::findOrCreate(MarketplacePermission::ViewMarketplacePage->value, 'web');
    test()->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo(MarketplacePermission::ViewMarketplacePage->value);

    config([
        'capell-marketplace.marketplace.base_url' => 'https://marketplace.test/api',
        'capell-marketplace.marketplace.web_url' => 'https://marketplace.test',
    ]);

    CapellCore::registerPackage('capell-app/seo-suite', version: '2.0.0');
    CapellCore::forcePackageInstalled('capell-app/seo-suite');

    Http::fake([
        'https://marketplace.test/api/extensions/seo-suite' => marketplaceDetailResponse(),
    ]);

    // A bare version comparison would offer the update here. The card and the
    // table both refuse it, and a detail page that disagreed with them would be
    // making an offer the product then declines downstream.
    expect(Livewire::test(MarketplaceExtensionDetailPage::class, ['slug' => 'seo-suite'])
        ->instance()
        ->canUpdate())->toBeTrue();

    MarketplaceInstallAttempt::query()->create([
        'composer_name' => 'capell-app/seo-suite',
        'extension_slug' => 'seo-suite',
        'extension_name' => 'Advanced SEO Suite',
        'kind' => 'plugin',
        'status' => MarketplaceInstallIntentStatus::Running,
    ]);

    expect(Livewire::test(MarketplaceExtensionDetailPage::class, ['slug' => 'seo-suite'])
        ->instance()
        ->canUpdate())->toBeFalse();
});

it('refuses an update call from a user who may not reach the marketplace', function (): void {
    Permission::findOrCreate(MarketplacePermission::ViewMarketplacePage->value, 'web');
    test()->actingAsAdmin();

    // Called on the instance rather than through a mounted page: the guard being
    // tested is on the method, and the point is that the method is reachable
    // without the render that canAccess() protects.
    $page = new MarketplaceExtensionDetailPage;
    $page->extensionSlug = 'seo-suite';

    expect(MarketplaceExtensionDetailPage::canAccess())->toBeFalse()
        ->and(function () use ($page): void {
            $page->updateExtension();
        })->toThrow(HttpException::class);
});

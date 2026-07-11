<?php

declare(strict_types=1);

use Capell\Marketplace\Enums\MarketplacePermission;
use Capell\Marketplace\Filament\Pages\MarketplaceExtensionDetailPage;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

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
        ->assertSee('data-marketplace-extension-screenshots', false)
        ->assertSee('data-marketplace-extension-docs', false)
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
        ->assertSee('data-marketplace-manual-install-commands', false)
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
        ->assertSee('Marketplace maintenance window.');
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

<?php

declare(strict_types=1);

use Capell\Core\Facades\CapellCore;
use Capell\Marketplace\Contracts\MarketplaceInstalledPackageVersionResolver;
use Capell\Marketplace\Enums\MarketplaceConnectionMode;
use Capell\Marketplace\Enums\MarketplaceInstallCapability;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Enums\MarketplaceOperationType;
use Capell\Marketplace\Enums\MarketplacePermission;
use Capell\Marketplace\Filament\Pages\MarketplaceExtensionDetailPage;
use Capell\Marketplace\Jobs\RunMarketplaceUpdateAttemptJob;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Capell\Marketplace\Models\MarketplaceInstance;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
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

it('submits permitted feedback and records the marketplace response status', function (): void {
    Permission::findOrCreate(MarketplacePermission::ViewMarketplacePage->value, 'web');
    test()->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo(MarketplacePermission::ViewMarketplacePage->value);

    config([
        'app.url' => 'https://client.test',
        'capell-marketplace.marketplace.base_url' => 'https://marketplace.test/api',
    ]);

    MarketplaceInstance::query()->create([
        'instance_id' => 'instance-feedback',
        'signing_secret_encrypted' => 'test-secret',
        'last_heartbeat_at' => now(),
    ]);

    Http::fake([
        'https://marketplace.test/api/extensions/seo-suite' => marketplaceDetailResponse(),
        'https://marketplace.test/api/extensions/seo-suite/licence-decision' => Http::response([
            'data' => [
                'licence_status' => 'active',
                'can_comment' => true,
                'can_rate' => true,
            ],
        ]),
        'https://marketplace.test/api/extensions/seo-suite/feedback' => Http::response([
            'data' => [
                'status' => 'published',
                'message' => 'Feedback accepted.',
            ],
        ]),
    ]);

    Livewire::test(MarketplaceExtensionDetailPage::class, ['slug' => 'seo-suite'])
        ->set('feedbackRating', 5)
        ->set('feedbackComment', 'The upgrade path was clear.')
        ->set('feedbackTip', 'Keep the release notes this useful.')
        ->call('submitFeedback')
        ->assertHasNoErrors()
        ->assertSet('feedbackStatus', 'published');

    Http::assertSent(fn ($request): bool => $request->url() === 'https://marketplace.test/api/extensions/seo-suite/feedback'
        && ($request->data()['rating'] ?? null) === 5
        && ($request->data()['comment'] ?? null) === 'The upgrade path was clear.'
        && ($request->data()['tip'] ?? null) === 'Keep the release notes this useful.');
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

it('derives detail decisions from licence and installation state', function (): void {
    Permission::findOrCreate(MarketplacePermission::ViewMarketplacePage->value, 'web');
    test()->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo(MarketplacePermission::ViewMarketplacePage->value);

    config([
        'capell-marketplace.marketplace.base_url' => 'https://marketplace.test/api',
        'capell-marketplace.marketplace.web_url' => 'https://marketplace.test',
    ]);

    CapellCore::registerPackage('capell-app/activation-suite', version: '2.0.0');
    CapellCore::forcePackageInstalled('capell-app/activation-suite');

    Http::fake([
        'https://marketplace.test/api/extensions/activation-suite' => Http::response([
            'data' => [
                'slug' => 'activation-suite',
                'name' => 'Activation Suite',
                'composer_name' => 'capell-app/activation-suite',
                'kind' => 'plugin',
                'latest_version' => '2.1.0',
                'documentation' => [
                    ['slug' => 'public-guide', 'private' => false],
                    ['slug' => 'operator-guide', 'private' => true],
                ],
                'performance' => ['frontendRenderBudgetMs' => 12],
                'contribution_summary' => ['admin-page' => 1, 'frontend-component' => 2],
                'install_eligibility' => ['state' => 'activation_required'],
                'licence' => [
                    'licence_status' => 'purchased',
                    'can_view_private_docs' => false,
                    'can_download' => false,
                    'can_install' => false,
                    'can_update' => false,
                    'can_rate' => true,
                    'can_comment' => false,
                    'runtime_allowed' => true,
                ],
            ],
        ]),
    ]);

    $page = Livewire::test(MarketplaceExtensionDetailPage::class, ['slug' => 'activation-suite'])
        ->instance();

    expect($page->publicDocumentation())->toHaveCount(1)
        ->and($page->publicDocumentation()[0]['slug'])->toBe('public-guide')
        ->and($page->canViewPrivateDocs())->toBeFalse()
        ->and($page->canDownload())->toBeFalse()
        ->and($page->canInstall())->toBeFalse()
        ->and($page->requiresLicenceKey())->toBeTrue()
        ->and($page->installedVersion())->toBe('2.0.0')
        ->and($page->contributionCount())->toBe(3)
        ->and($page->frontendRenderBudgetLabel())->not->toBeNull()
        ->and($page->manualInstallCommands())->toHaveKeys(['composer', 'install'])
        ->and($page->shouldVerifySite())->toBeTrue()
        ->and($page->canSubmitFeedback())->toBeTrue()
        ->and($page->ratingIsRequired())->toBeTrue();
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

it('queues a permitted update from the detail page and exposes the operation side effect', function (): void {
    Permission::findOrCreate(MarketplacePermission::ViewMarketplacePage->value, 'web');
    test()->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo(MarketplacePermission::ViewMarketplacePage->value);

    config([
        'capell-marketplace.marketplace.base_url' => 'https://marketplace.test/api',
    ]);

    CapellCore::registerPackage('capell-app/seo-suite', version: '2.0.0');
    CapellCore::forcePackageInstalled('capell-app/seo-suite');
    app()->instance(MarketplaceInstalledPackageVersionResolver::class, new class implements MarketplaceInstalledPackageVersionResolver
    {
        public function prettyVersion(string $composerName): ?string
        {
            return $composerName === 'capell-app/seo-suite' ? '2.0.0' : null;
        }
    });
    Queue::fake();

    Http::fake([
        'https://marketplace.test/api/extensions/seo-suite' => marketplaceDetailResponse(),
        'https://marketplace.test/api/extensions/by-composer*' => Http::response([
            'data' => [[
                'slug' => 'seo-suite',
                'name' => 'Advanced SEO Suite',
                'composer_name' => 'capell-app/seo-suite',
                'kind' => 'plugin',
                'latest_version' => '2.1.0',
                'install_eligibility' => [
                    'state' => 'authorized',
                    'can_install' => true,
                    'can_update' => true,
                    'can_run_existing' => true,
                ],
            ]],
        ]),
    ]);

    Livewire::test(MarketplaceExtensionDetailPage::class, ['slug' => 'seo-suite'])
        ->call('updateExtension')
        ->assertNoRedirect();

    $attempt = MarketplaceInstallAttempt::query()->sole();

    expect($attempt->composer_name)->toBe('capell-app/seo-suite')
        ->and($attempt->operation)->toBe(MarketplaceOperationType::Update)
        ->and($attempt->version_constraint)->toBe('^2.1.0')
        ->and($attempt->status)->toBe(MarketplaceInstallIntentStatus::Queued);

    Queue::assertPushed(
        RunMarketplaceUpdateAttemptJob::class,
        fn (RunMarketplaceUpdateAttemptJob $job): bool => $job->uniqueId() === (string) $attempt->getKey(),
    );
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

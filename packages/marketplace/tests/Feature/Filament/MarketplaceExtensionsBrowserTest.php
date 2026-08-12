<?php

declare(strict_types=1);

use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\Core\Facades\CapellCore;
use Capell\Marketplace\Actions\BuildMarketplaceSelectionReviewAction;
use Capell\Marketplace\Actions\RecordThemeInstallIntentAction;
use Capell\Marketplace\Contracts\MarketplaceComposerChangePublisher;
use Capell\Marketplace\Data\MarketplaceComposerPublicationRequestData;
use Capell\Marketplace\Data\MarketplaceComposerPublicationResultData;
use Capell\Marketplace\Data\MarketplaceSelectionInputData;
use Capell\Marketplace\Data\MarketplaceSelectionReviewData;
use Capell\Marketplace\Enums\MarketplaceConnectionMode;
use Capell\Marketplace\Enums\MarketplaceInstallCapability;
use Capell\Marketplace\Enums\MarketplaceInstallFailureStage;
use Capell\Marketplace\Enums\MarketplaceInstallFlowSessionStatus;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Enums\MarketplacePermission;
use Capell\Marketplace\Enums\MarketplaceReadinessStatus;
use Capell\Marketplace\Filament\Livewire\MarketplaceExtensionsBrowser;
use Capell\Marketplace\Filament\Pages\MarketplacePackageOperationsPage;
use Capell\Marketplace\Filament\Support\MarketplaceCatalogueRecordProvider;
use Capell\Marketplace\Filament\Support\MarketplaceCatalogueTable;
use Capell\Marketplace\Jobs\RunMarketplaceInstallAttemptJob;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Capell\Marketplace\Models\MarketplaceInstallFlowSession;
use Capell\Marketplace\Models\MarketplaceInstallIntent;
use Capell\Marketplace\Models\MarketplaceInstance;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(CreatesAdminUser::class);

beforeEach(function (): void {
    Cache::flush();

    config([
        'capell-marketplace.marketplace.base_url' => 'https://marketplace.test/api',
        'capell-marketplace.marketplace.cache_ttl_seconds' => 300,
    ]);
});

it('waits to render the marketplace table until results have been fetched', function (): void {
    grantMarketplaceBrowserViewOnlyAccess();

    Http::fake([
        'https://marketplace.test/api/extensions*' => Http::response([
            'data' => [],
            'links' => ['next' => null],
        ]),
    ]);

    Livewire::test(MarketplaceExtensionsBrowser::class)
        ->assertSet('marketplaceResultsFetched', false)
        ->assertSee(__('capell-marketplace::marketplace.filters.loading_heading'))
        ->assertSee('fi-loading-indicator', false)
        ->assertDontSee(__('capell-marketplace::marketplace.filters.empty_heading'))
        ->call('loadMarketplaceResults')
        ->assertSet('marketplaceResultsFetched', true)
        ->assertSee('wire:loading.flex', false)
        ->assertDontSee(__('capell-marketplace::marketplace.explorer.heading'))
        ->assertSee(__('capell-marketplace::marketplace.filters.search_placeholder'))
        ->assertSee(__('capell-marketplace::marketplace.filters.trigger_label'))
        ->assertSee(__('capell-marketplace::marketplace.filters.heading'))
        ->assertSee(__('capell-marketplace::marketplace.filters.installed_status'))
        ->assertSee('disabled', false)
        ->assertSee('cursor-not-allowed', false)
        ->assertSee(__('capell-marketplace::marketplace.selection.install_button_disabled_tooltip'), false)
        ->assertSee(__('capell-marketplace::marketplace.filters.empty_heading'));
});

it('rejects direct marketplace browser requests without marketplace page access', function (): void {
    test()->actingAsAdmin();

    Livewire::test(MarketplaceExtensionsBrowser::class)
        ->assertForbidden();
});

function grantMarketplaceBrowserViewOnlyAccess(): void
{
    Permission::create(['name' => 'View:ExtensionsPage', 'guard_name' => 'web']);
    test()->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo('View:ExtensionsPage');
}

function grantMarketplaceBrowserManagementAccess(): void
{
    grantMarketplaceBrowserViewOnlyAccess();

    Permission::create(['name' => ExtensionsPage::MANAGE_PERMISSION, 'guard_name' => 'web']);
    test()->authenticatedUser()->givePermissionTo(ExtensionsPage::MANAGE_PERMISSION);
}

function grantMarketplacePageOnlyAccess(): void
{
    Permission::create(['name' => MarketplacePermission::ViewMarketplacePage->value, 'guard_name' => 'web']);
    test()->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo(MarketplacePermission::ViewMarketplacePage->value);
}

function tagMarketplaceBrowserPublisher(MarketplaceComposerChangePublisher $publisher): void
{
    app()->instance('test.marketplace.browser-composer-change-publisher', $publisher);
    app()->tag(['test.marketplace.browser-composer-change-publisher'], MarketplaceComposerChangePublisher::TAG);
}

it('renders author and rating information in the marketplace card', function (): void {
    grantMarketplaceBrowserViewOnlyAccess();

    Http::fake([
        'https://marketplace.test/api/extensions*' => Http::response([
            'data' => [
                marketplaceBrowserExtensionPayload([
                    'slug' => 'seo-audit',
                    'name' => 'SEO Audit',
                    'composer_name' => 'capell-app/seo-audit',
                    'image_url' => 'https://marketplace.test/images/seo-audit.png',
                    'author_name' => 'Capell Labs',
                    'author_slug' => 'capell-labs',
                    'ratings_summary' => [
                        'average' => 4.5,
                        'count' => 18,
                    ],
                ]),
            ],
            'links' => ['next' => null],
        ]),
    ]);

    Livewire::test(MarketplaceExtensionsBrowser::class)
        ->call('loadMarketplaceResults')
        ->assertSeeHtml('src="https://marketplace.test/images/seo-audit.png"')
        ->assertSee('Capell Labs')
        ->assertSee('4.5')
        ->assertSee('18 ratings')
        ->assertSee(__('capell-marketplace::marketplace.card.rating_aria', [
            'rating' => '4.5',
            'count' => '18 ratings',
        ]), false)
        ->assertSee("filterByMarketplaceAuthor('capell-labs', 'Capell Labs')", false)
        ->assertSee(__('capell-marketplace::marketplace.card.author_filter_tooltip', [
            'author' => 'Capell Labs',
        ]), false);
});

it('renders released beta and labs badges with Capell All inclusion', function (): void {
    grantMarketplaceBrowserViewOnlyAccess();

    Http::fake([
        'https://marketplace.test/api/extensions*' => Http::response([
            'data' => [
                marketplaceBrowserExtensionPayload([
                    'slug' => 'released-suite',
                    'name' => 'Released Suite',
                    'composer_name' => 'capell-app/released-suite',
                    'catalogue_role' => 'extension',
                    'maturity' => 'stable',
                    'maturity_label' => 'Released',
                    'included_with_capell_all' => true,
                ]),
                marketplaceBrowserExtensionPayload([
                    'slug' => 'beta-suite',
                    'name' => 'Beta Suite',
                    'composer_name' => 'capell-app/beta-suite',
                    'catalogue_role' => 'extension',
                    'maturity' => 'beta',
                    'maturity_label' => 'Beta',
                    'included_with_capell_all' => false,
                ]),
                marketplaceBrowserExtensionPayload([
                    'slug' => 'labs-suite',
                    'name' => 'Labs Suite',
                    'composer_name' => 'vendor/labs-suite',
                    'catalogue_role' => 'extension',
                    'maturity' => 'labs',
                    'maturity_label' => 'Labs',
                    'included_with_capell_all' => false,
                ]),
            ],
            'links' => ['next' => null],
        ]),
    ]);

    Livewire::test(MarketplaceExtensionsBrowser::class)
        ->call('loadMarketplaceResults')
        ->assertSeeHtml('data-release-status="stable"')
        ->assertSeeHtml('data-release-status="beta"')
        ->assertSeeHtml('data-release-status="labs"')
        ->assertSee(__('capell-admin::marketplace.release_status.stable'))
        ->assertSee(__('capell-admin::marketplace.release_status.beta'))
        ->assertSee(__('capell-admin::marketplace.release_status.labs'))
        ->assertSeeHtml('data-capell-all-included')
        ->assertSee(__('capell-admin::marketplace.capell_all.included'));
});

it('renders marketplace extension cards with instant selection buttons', function (): void {
    grantMarketplaceBrowserManagementAccess();

    Http::fake([
        'https://marketplace.test/api/extensions*' => Http::response([
            'data' => [
                marketplaceBrowserExtensionPayload([
                    'slug' => 'image-suite',
                    'name' => 'Image Suite',
                    'composer_name' => 'capell-app/image-suite',
                    'image_url' => 'https://marketplace.test/images/image-suite.png',
                ]),
            ],
            'links' => ['next' => null],
        ]),
    ]);

    $html = Livewire::test(MarketplaceExtensionsBrowser::class)
        ->call('loadMarketplaceResults')
        ->html();

    expect($html)
        ->toContain('src="https://marketplace.test/images/image-suite.png"')
        ->toContain('toggleMarketplaceRecord')
        ->toContain('removeMarketplaceRecord')
        ->toContain('aria-pressed')
        ->toContain('data-marketplace-selection-primary="capell-app/image-suite"')
        ->not->toContain('@js($composerName)')
        ->and(preg_match('/<button\\b[^>]*data-marketplace-selection-primary="capell-app\\/image-suite"[^>]*>/s', $html))
        ->toBe(1);
});

it('tracks marketplace selections through the Filament table card surface', function (): void {
    grantMarketplaceBrowserManagementAccess();

    config([
        'app.url' => 'https://example.test',
        'capell-marketplace.instance.id' => 'instance-123',
        'capell-marketplace.marketplace.webhook_secret' => 'test-secret',
    ]);

    MarketplaceInstance::query()->create([
        'instance_id' => 'instance-123',
        'signing_secret_encrypted' => 'test-secret',
        'connection_mode' => MarketplaceConnectionMode::AccountLinked,
        'account_id' => 'acct_123',
        'account_email_verified_at' => now(),
        'last_heartbeat_at' => now(),
    ]);

    Http::fake([
        'https://marketplace.test/api/extensions?*' => Http::response([
            'data' => [
                marketplaceBrowserExtensionPayload([
                    'slug' => 'seo-suite',
                    'name' => 'SEO Suite',
                    'composer_name' => 'capell-app/seo-suite',
                ]),
            ],
            'links' => ['next' => null],
        ]),
        'https://marketplace.test/api/extensions/seo-suite' => Http::response([
            'data' => marketplaceBrowserExtensionPayload([
                'slug' => 'seo-suite',
                'name' => 'SEO Suite',
                'composer_name' => 'capell-app/seo-suite',
            ]),
        ]),
        'https://marketplace.test/api/extensions/seo-suite/install-authorization' => Http::response([
            'data' => [
                'composer_name' => 'capell-app/seo-suite',
                'version_constraint' => '^2.1',
            ],
        ]),
        'https://marketplace.test/api/extensions/install-intents' => Http::response(['data' => ['recorded' => true]]),
    ]);

    Livewire::test(MarketplaceExtensionsBrowser::class)
        ->call('loadMarketplaceResults')
        ->assertSee('SEO Suite')
        ->assertSee('toggleMarketplaceRecord', false)
        ->assertSee('aria-pressed', false)
        ->call('toggleMarketplaceSelection', 'capell-app/seo-suite')
        ->assertSet('selectedMarketplaceComposerNames', ['capell-app/seo-suite'])
        ->assertSee('toggleMarketplaceRecord', false)
        ->assertSeeHtml('data-capell-marketplace-browse-actions')
        ->assertSee(__('capell-marketplace::marketplace.selection.reviewing_button'))
        ->assertSee(__('capell-marketplace::marketplace.selection.install_footer_action'));
});

it('reviews marketplace selections after search changes hide the selected records', function (): void {
    grantMarketplaceBrowserManagementAccess();

    Http::fake(function ($request) {
        $search = (string) ($request->data()['search'] ?? '');
        $payloads = match ($search) {
            'media' => [
                marketplaceBrowserExtensionPayload([
                    'slug' => 'media-curator',
                    'name' => 'Media Curator',
                    'composer_name' => 'capell-app/media-curator',
                ]),
            ],
            'capell-app/media-curator' => [
                marketplaceBrowserExtensionPayload([
                    'slug' => 'media-curator',
                    'name' => 'Media Curator',
                    'composer_name' => 'capell-app/media-curator',
                ]),
            ],
            'capell-app/seo-suite' => [
                marketplaceBrowserExtensionPayload([
                    'slug' => 'seo-suite',
                    'name' => 'SEO Suite',
                    'composer_name' => 'capell-app/seo-suite',
                ]),
            ],
            'nothing' => [],
            default => [
                marketplaceBrowserExtensionPayload([
                    'slug' => 'seo-suite',
                    'name' => 'SEO Suite',
                    'composer_name' => 'capell-app/seo-suite',
                ]),
            ],
        };

        return Http::response([
            'data' => $payloads,
            'links' => ['next' => null],
        ]);
    });

    Livewire::test(MarketplaceExtensionsBrowser::class)
        ->call('loadMarketplaceResults')
        ->call('toggleMarketplaceSelection', 'capell-app/seo-suite')
        ->set('tableSearch', 'media')
        ->call('toggleMarketplaceSelection', 'capell-app/media-curator')
        ->assertSet('selectedMarketplaceComposerNames', [
            'capell-app/seo-suite',
            'capell-app/media-curator',
        ])
        ->set('tableSearch', 'nothing')
        ->call('showMarketplaceInstallReview')
        ->assertSet('marketplaceStep', 'review')
        ->assertSee('SEO Suite')
        ->assertSee('Media Curator')
        ->assertSee(trans_choice('capell-marketplace::marketplace.selection.review_summary', 2, ['count' => 2]))
        ->assertSee(trans_choice('capell-marketplace::marketplace.selection.final_install_count_button', 2, ['count' => 2]));

    Http::assertNotSent(fn ($request): bool => str_contains((string) $request->url(), '/extensions/by-composer'));
});

it('shows blocked marketplace extensions in the default not installed marketplace results', function (): void {
    grantMarketplaceBrowserManagementAccess();

    Http::fake([
        'https://marketplace.test/api/extensions*' => Http::response([
            'data' => [
                marketplaceBrowserExtensionPayload([
                    'slug' => 'seo-suite',
                    'name' => 'SEO Suite',
                    'composer_name' => 'capell-app/seo-suite',
                ]),
                marketplaceBrowserExtensionPayload([
                    'slug' => 'blocked-suite',
                    'name' => 'Blocked Suite',
                    'composer_name' => 'capell-app/blocked-suite',
                    'install_eligibility' => [
                        'state' => 'blocked',
                        'block_reason' => 'email_verification_required',
                    ],
                ]),
            ],
            'links' => ['next' => null],
        ]),
    ]);

    Livewire::test(MarketplaceExtensionsBrowser::class)
        ->call('loadMarketplaceResults')
        ->assertSee('SEO Suite')
        ->assertSee('Blocked Suite')
        ->call('toggleMarketplaceSelection', 'capell-app/blocked-suite')
        ->assertSet('selectedMarketplaceComposerNames', ['capell-app/blocked-suite'])
        ->call('showMarketplaceInstallReview')
        ->assertSee(__('capell-marketplace::marketplace.selection.premium_notice'));

    Http::assertSent(fn ($request): bool => str_starts_with((string) $request->url(), 'https://marketplace.test/api/extensions?')
        && ! array_key_exists('installed_status', $request->data()));
});

it('allows Capell Membership extensions into the hosted install review', function (): void {
    grantMarketplaceBrowserManagementAccess();

    Http::fake([
        'https://marketplace.test/api/extensions*' => Http::response([
            'data' => [
                marketplaceBrowserExtensionPayload([
                    'slug' => 'filament-peek',
                    'name' => 'Filament Peek',
                    'composer_name' => 'capell-app/filament-peek',
                    'install_state' => 'capell_all_required',
                    'install_eligibility' => [
                        'state' => 'capell_all_required',
                        'can_install' => false,
                        'reason' => 'capell_all_required',
                    ],
                ]),
            ],
            'links' => ['next' => null],
        ]),
    ]);

    Livewire::test(MarketplaceExtensionsBrowser::class)
        ->call('loadMarketplaceResults')
        ->call('toggleMarketplaceSelection', 'capell-app/filament-peek')
        ->assertSet('selectedMarketplaceComposerNames', ['capell-app/filament-peek'])
        ->call('showMarketplaceInstallReview')
        ->assertSet('marketplaceStep', 'review')
        ->assertSee(__('capell-marketplace::marketplace.selection.premium_notice'));
});

it('renders the offline licence fallback in an activation-required review', function (): void {
    grantMarketplaceBrowserManagementAccess();

    Http::fake([
        'https://marketplace.test/api/extensions*' => Http::response([
            'data' => [
                marketplaceBrowserExtensionPayload([
                    'slug' => 'activation-suite',
                    'name' => 'Activation Suite',
                    'composer_name' => 'capell-app/activation-suite',
                    'is_paid' => true,
                    'install_state' => 'activation_required',
                    'install_eligibility' => [
                        'state' => 'activation_required',
                    ],
                ]),
            ],
            'links' => ['next' => null],
        ]),
    ]);

    Livewire::test(MarketplaceExtensionsBrowser::class)
        ->call('loadMarketplaceResults')
        ->call('toggleMarketplaceSelection', 'capell-app/activation-suite')
        ->call('showMarketplaceInstallReview')
        ->assertSet('marketplaceStep', 'review')
        ->assertSee('data-capell-marketplace-licence-form', false)
        ->assertSee(__('capell-marketplace::marketplace.install.license_key_label'))
        ->assertSee(__('capell-marketplace::marketplace.install.license_key_help'));
});

it('queues a free marketplace extension install from the grouped browser footer', function (): void {
    grantMarketplaceBrowserManagementAccess();
    Queue::fake();

    tagMarketplaceBrowserPublisher(new class implements MarketplaceComposerChangePublisher
    {
        public function publish(MarketplaceComposerPublicationRequestData $request): MarketplaceComposerPublicationResultData
        {
            return new MarketplaceComposerPublicationResultData(pullRequestUrl: 'https://github.test/capell/pulls/authentication-log');
        }
    });

    Http::fake([
        'https://marketplace.test/api/extensions?*' => Http::response([
            'data' => [
                marketplaceBrowserExtensionPayload([
                    'slug' => 'authentication-log',
                    'name' => 'Authentication Log',
                    'composer_name' => 'capell-app/authentication-log',
                    'latest_version' => '1.0.0',
                    'install_confirmation' => [
                        'summary' => 'Adds authentication log tables and admin screens.',
                    ],
                    'install_options' => [
                        ['key' => 'seed_examples', 'type' => 'checkbox', 'label' => 'Seed examples', 'default' => true],
                    ],
                ]),
            ],
            'links' => ['next' => null],
        ]),
        'https://marketplace.test/api/extensions/authentication-log' => Http::response([
            'data' => marketplaceBrowserExtensionPayload([
                'slug' => 'authentication-log',
                'name' => 'Authentication Log',
                'composer_name' => 'capell-app/authentication-log',
                'latest_version' => '1.0.0',
                'install_confirmation' => [
                    'summary' => 'Adds authentication log tables and admin screens.',
                ],
                'install_options' => [
                    ['key' => 'seed_examples', 'type' => 'checkbox', 'label' => 'Seed examples', 'default' => true],
                ],
            ]),
        ]),
        'https://marketplace.test/api/extensions/by-composer*' => Http::response([
            'data' => [
                marketplaceBrowserExtensionPayload([
                    'slug' => 'authentication-log',
                    'name' => 'Authentication Log',
                    'composer_name' => 'capell-app/authentication-log',
                    'latest_version' => '1.0.0',
                    'install_confirmation' => [
                        'summary' => 'Adds authentication log tables and admin screens.',
                    ],
                    'install_options' => [
                        ['key' => 'seed_examples', 'type' => 'checkbox', 'label' => 'Seed examples', 'default' => true],
                    ],
                ]),
            ],
        ]),
    ]);

    $component = Livewire::test(MarketplaceExtensionsBrowser::class)
        ->call('loadMarketplaceResults')
        ->assertSee('Authentication Log')
        ->call('toggleMarketplaceSelection', 'capell-app/login-audit')
        ->assertSee(__('capell-marketplace::marketplace.selection.install_footer_action'))
        ->call('showMarketplaceInstallReview')
        ->assertSet('marketplaceStep', 'review')
        ->assertSee(__('capell-marketplace::marketplace.selection.review_heading'))
        ->assertSee(trans_choice('capell-marketplace::marketplace.selection.final_install_count_button', 1, ['count' => 1]))
        ->assertSee(__('capell-marketplace::marketplace.selection.confirm_download_install_label'))
        ->assertSee(__('capell-marketplace::marketplace.selection.review_not_started_notice'))
        ->assertSee('Adds authentication log tables and admin screens.')
        ->assertSee('Seed examples')
        ->assertSet('installReviewedMarketplaceExtensionsConfirmed', false)
        ->assertSet('selectedMarketplaceInstallOptions.seed_examples', true)
        ->assertSee(trans_choice('capell-marketplace::marketplace.selection.review_summary', 1, ['count' => 1]))
        ->assertDontSee('Continue install')
        ->assertDontSee(__('capell-marketplace::marketplace.install.free'))
        ->assertDontSee('Total:')
        ->assertSee(__('capell-marketplace::marketplace.selection.back_to_table'))
        ->call('backToMarketplaceTable')
        ->assertSet('marketplaceStep', 'browse')
        ->assertSet('selectedMarketplaceComposerNames', ['capell-app/login-audit'])
        ->call('showMarketplaceInstallReview')
        ->call('installReviewedMarketplaceExtensions')
        ->assertSet('selectedMarketplaceComposerNames', ['capell-app/login-audit'])
        ->set('installReviewedMarketplaceExtensionsConfirmed', true)
        ->call('installReviewedMarketplaceExtensions')
        ->assertSet('selectedMarketplaceComposerNames', [])
        // Confirming keeps the operator in the modal and shows the operation
        // running, instead of navigating them away from the catalogue at the
        // moment the thing they asked for starts working.
        ->assertSet('marketplaceStep', 'progress')
        ->assertNoRedirect();

    $attempt = expectPresent(MarketplaceInstallAttempt::query()->first());

    expect($attempt)->not->toBeNull()
        ->and($attempt->extension_slug)->toBe('authentication-log')
        ->and($attempt->extension_name)->toBe('Authentication Log')
        ->and($attempt->composer_name)->toBe('capell-app/login-audit')
        ->and($attempt->version_constraint)->toBe('^1.0.0')
        ->and($attempt->requested_options)->toBe(['seed_examples' => true])
        ->and($attempt->status)->toBe(MarketplaceInstallIntentStatus::Queued);

    // The redirect is still reachable — it is now something the operator picks.
    $component
        ->assertSet('activeMarketplaceInstallAttemptIds', [(int) $attempt->getKey()])
        ->call('viewMarketplaceInstallOperations')
        ->assertRedirect(MarketplacePackageOperationsPage::getUrl([
            'tab' => 'active',
            'operation' => $attempt->getKey(),
        ]));

    Queue::assertPushed(RunMarketplaceInstallAttemptJob::class);

    Http::assertNotSent(fn ($request): bool => (string) $request->url() === 'https://marketplace.test/api/extensions/authentication-log/install-authorization');
});

it('allows premium marketplace extensions into the review step before account or checkout flow', function (): void {
    grantMarketplaceBrowserManagementAccess();

    Http::fake([
        'https://marketplace.test/api/extensions?*' => Http::response([
            'data' => [
                marketplaceBrowserExtensionPayload([
                    'slug' => 'premium-suite',
                    'name' => 'Premium Suite',
                    'composer_name' => 'capell-app/premium-suite',
                    'price_cents' => 4900,
                    'is_paid' => true,
                ]),
            ],
            'links' => ['next' => null],
        ]),
    ]);

    Livewire::test(MarketplaceExtensionsBrowser::class)
        ->call('loadMarketplaceResults')
        ->assertSee(__('capell-marketplace::marketplace.selection.select'))
        ->call('installMarketplaceRecordFromCard', 'capell-app/premium-suite')
        ->assertSet('selectedMarketplaceComposerNames', ['capell-app/premium-suite'])
        ->assertSet('marketplaceStep', 'review')
        ->call('backToMarketplaceTable')
        ->call('toggleMarketplaceSelection', 'capell-app/premium-suite')
        ->assertSet('selectedMarketplaceComposerNames', [])
        ->call('toggleMarketplaceSelection', 'capell-app/premium-suite')
        ->assertDontSee(__('capell-marketplace::marketplace.selection.blocked.paid'))
        ->call('showMarketplaceInstallReview')
        ->assertSet('marketplaceStep', 'review')
        ->assertSee('Premium Suite')
        ->assertSee(__('capell-marketplace::marketplace.selection.premium_badge'))
        ->assertSee(__('capell-marketplace::marketplace.selection.premium_notice'))
        ->assertSee(trans_choice('capell-marketplace::marketplace.selection.final_install_count_button', 1, ['count' => 1]))
        ->assertSee(trans_choice('capell-marketplace::marketplace.selection.review_summary', 1, ['count' => 1]))
        ->assertDontSee('Continue install')
        ->assertDontSee(__('capell-marketplace::marketplace.install.free'))
        ->assertDontSee('$49.00')
        ->assertDontSee('Total:');
});

it('prevents grouped marketplace selection without extension management permission', function (): void {
    grantMarketplaceBrowserViewOnlyAccess();

    Http::fake([
        'https://marketplace.test/api/extensions?*' => Http::response([
            'data' => [
                marketplaceBrowserExtensionPayload([
                    'slug' => 'seo-suite',
                    'name' => 'SEO Suite',
                    'composer_name' => 'capell-app/seo-suite',
                ]),
            ],
            'links' => ['next' => null],
        ]),
    ]);

    Livewire::test(MarketplaceExtensionsBrowser::class)
        ->call('loadMarketplaceResults')
        ->assertSee('SEO Suite')
        ->call('toggleMarketplaceSelection', 'capell-app/seo-suite')
        ->assertSet('selectedMarketplaceComposerNames', [])
        ->assertSee(__('capell-marketplace::marketplace.selection.blocked.permission'));
});

it('does not expose local extension state to marketplace-only users', function (): void {
    grantMarketplacePageOnlyAccess();

    CapellCore::registerPackage('capell-app/installed-suite', version: '1.0.0');
    CapellCore::forcePackageInstalled('capell-app/installed-suite');

    Http::fake([
        'https://marketplace.test/api/extensions*' => Http::response([
            'data' => [
                marketplaceBrowserExtensionPayload([
                    'slug' => 'installed-suite',
                    'name' => 'Installed Suite',
                    'composer_name' => 'capell-app/installed-suite',
                ]),
            ],
            'links' => ['next' => null],
        ]),
    ]);

    Livewire::test(MarketplaceExtensionsBrowser::class, ['includeLocalExtensionState' => true])
        ->call('loadMarketplaceResults')
        ->assertSee('Installed Suite')
        ->assertDontSee(__('capell-marketplace::marketplace.selection.already_installed'));

    Http::assertSent(fn ($request): bool => str_starts_with((string) $request->url(), 'https://marketplace.test/api/extensions?')
        && ! str_contains((string) ($request->data()['installed_composer_names'] ?? ''), 'capell-app/installed-suite'));
});

it('shows marketplace extensions when a package install operation is already active', function (): void {
    grantMarketplaceBrowserManagementAccess();

    MarketplaceInstallAttempt::query()->create([
        'composer_name' => 'capell-app/seo-suite',
        'extension_slug' => 'seo-suite',
        'extension_name' => 'SEO Suite',
        'kind' => 'tool',
        'status' => MarketplaceInstallIntentStatus::Queued,
        'composer_command' => 'composer require capell-app/seo-suite:^2.1',
        'version_constraint' => '^2.1',
        'queued_at' => now(),
    ]);

    Http::fake([
        'https://marketplace.test/api/extensions?*' => Http::response([
            'data' => [
                marketplaceBrowserExtensionPayload([
                    'slug' => 'seo-suite',
                    'name' => 'SEO Suite',
                    'composer_name' => 'capell-app/seo-suite',
                ]),
            ],
            'links' => ['next' => null],
        ]),
    ]);

    Livewire::test(MarketplaceExtensionsBrowser::class)
        ->call('loadMarketplaceResults')
        ->assertSee('SEO Suite')
        ->assertSee(__('capell-marketplace::marketplace.card.install_in_progress'))
        ->assertSee(__('capell-marketplace::marketplace.selection.blocked.install_in_progress'), false);
});

it('includes available dependencies in the grouped marketplace install review', function (): void {
    grantMarketplaceBrowserManagementAccess();
    Queue::fake();

    tagMarketplaceBrowserPublisher(new class implements MarketplaceComposerChangePublisher
    {
        public function publish(MarketplaceComposerPublicationRequestData $request): MarketplaceComposerPublicationResultData
        {
            return new MarketplaceComposerPublicationResultData(pullRequestUrl: 'https://github.test/capell/pulls/grouped-dependencies');
        }
    });

    Http::fake([
        'https://marketplace.test/api/extensions/seo-suite' => Http::response([
            'data' => marketplaceBrowserExtensionPayload([
                'slug' => 'seo-suite',
                'name' => 'SEO Suite',
                'composer_name' => 'capell-app/seo-suite',
                'dependencies' => ['requires' => ['capell-app/html-cache']],
            ]),
        ]),
        'https://marketplace.test/api/extensions/html-cache' => Http::response([
            'data' => marketplaceBrowserExtensionPayload([
                'slug' => 'html-cache',
                'name' => 'HTML Cache',
                'composer_name' => 'capell-app/html-cache',
            ]),
        ]),
        'https://marketplace.test/api/extensions*' => Http::response([
            'data' => [
                marketplaceBrowserExtensionPayload([
                    'slug' => 'seo-suite',
                    'name' => 'SEO Suite',
                    'composer_name' => 'capell-app/seo-suite',
                    'dependencies' => ['requires' => ['capell-app/html-cache']],
                ]),
                marketplaceBrowserExtensionPayload([
                    'slug' => 'html-cache',
                    'name' => 'HTML Cache',
                    'composer_name' => 'capell-app/html-cache',
                ]),
            ],
            'links' => ['next' => null],
        ]),
    ]);

    Livewire::test(MarketplaceExtensionsBrowser::class)
        ->call('loadMarketplaceResults')
        ->call('toggleMarketplaceSelection', 'capell-app/seo-suite')
        ->assertSee('SEO Suite')
        ->assertSee('HTML Cache')
        ->call('showMarketplaceInstallReview')
        ->assertSet('marketplaceStep', 'review')
        ->assertSee(__('capell-marketplace::marketplace.selection.dependency_badge'))
        ->assertSee(trans_choice('capell-marketplace::marketplace.selection.dependency_count', 1, ['count' => 1]))
        ->assertSee(trans_choice('capell-marketplace::marketplace.selection.final_install_count_button', 2, ['count' => 2]))
        ->set('installReviewedMarketplaceExtensionsConfirmed', true)
        ->call('installReviewedMarketplaceExtensions');

    expect(MarketplaceInstallAttempt::query()->pluck('composer_name')->sort()->values()->all())->toEqual([
        'capell-app/html-cache',
        'capell-app/seo-suite',
    ]);
});

it('blocks grouped marketplace installs when a selected extension has a missing dependency', function (): void {
    grantMarketplaceBrowserManagementAccess();

    Http::fake([
        'https://marketplace.test/api/extensions*' => Http::response([
            'data' => [
                marketplaceBrowserExtensionPayload([
                    'slug' => 'seo-suite',
                    'name' => 'SEO Suite',
                    'composer_name' => 'capell-app/seo-suite',
                    'dependencies' => ['requires' => ['capell-app/missing-cache']],
                ]),
            ],
            'links' => ['next' => null],
        ]),
    ]);

    Livewire::test(MarketplaceExtensionsBrowser::class)
        ->call('loadMarketplaceResults')
        ->call('toggleMarketplaceSelection', 'capell-app/seo-suite')
        ->call('showMarketplaceInstallReview')
        ->assertSet('marketplaceStep', 'review')
        ->assertSee(__('capell-marketplace::marketplace.selection.missing_dependencies', [
            'dependencies' => 'capell-app/missing-cache',
        ]))
        ->set('installReviewedMarketplaceExtensionsConfirmed', true)
        ->call('installReviewedMarketplaceExtensions');

    expect(MarketplaceInstallAttempt::query()->count())->toBe(0);
});

it('opens marketplace searches from an empty local extensions search', function (): void {
    grantMarketplaceBrowserViewOnlyAccess();

    MarketplaceInstance::query()->create([
        'instance_id' => 'instance-123',
        'signing_secret_encrypted' => 'test-secret',
        'connection_mode' => MarketplaceConnectionMode::AccountLinked,
        'account_id' => 'acct_123',
        'account_email_verified_at' => now(),
        'last_heartbeat_at' => now(),
    ]);

    Http::fake([
        'https://marketplace.test/api/extensions*' => Http::response([
            'data' => [],
            'links' => ['next' => null],
        ]),
    ]);

    Livewire::test(ExtensionsPage::class)
        ->assertSuccessful()
        ->assertSee(__('capell-marketplace::marketplace.marketplace.extensions_marketplace'));
});

it('hydrates marketplace browser searches from marketplace urls', function (): void {
    grantMarketplaceBrowserViewOnlyAccess();

    Http::fake([
        'https://marketplace.test/api/extensions*' => Http::response([
            'data' => [
                marketplaceBrowserExtensionPayload([
                    'slug' => 'filament-peak',
                    'name' => 'Filament Peak',
                    'composer_name' => 'pboivin/filament-peek',
                ]),
            ],
            'links' => ['next' => null],
        ]),
    ]);

    Livewire::test(MarketplaceExtensionsBrowser::class, ['initialSearch' => 'filament'])
        ->assertSet('tableSearch', 'filament')
        ->call('loadMarketplaceResults')
        ->assertSee('Filament Peak');

    Http::assertSent(fn ($request): bool => str_starts_with((string) $request->url(), 'https://marketplace.test/api/extensions?')
        && ($request->data()['search'] ?? null) === 'filament'
        && ! array_key_exists('installed_status', $request->data()));
});

it('passes changed marketplace browser filters and pagination to the api while forcing available extensions', function (): void {
    grantMarketplaceBrowserViewOnlyAccess();

    Http::fake([
        'https://marketplace.test/api/extensions*' => Http::response([
            'data' => [
                marketplaceBrowserExtensionPayload([
                    'slug' => 'installed-suite',
                    'name' => 'Installed Suite',
                    'composer_name' => 'capell-app/installed-suite',
                ]),
            ],
            'links' => ['next' => null],
            'meta' => [
                'current_page' => 2,
                'per_page' => 18,
                'total' => 20,
            ],
        ]),
    ]);

    Livewire::test(MarketplaceExtensionsBrowser::class)
        ->set('tableFilters.installed_status.value', true)
        ->set('tableFilters.sort.value', 'latest')
        ->set('tableRecordsPerPage', 18)
        ->set('paginators.page', 2)
        ->call('loadMarketplaceResults')
        ->assertSee('Installed Suite');

    Http::assertSent(fn ($request): bool => str_starts_with((string) $request->url(), 'https://marketplace.test/api/extensions?')
        && ! array_key_exists('installed_status', $request->data())
        && ($request->data()['sort'] ?? null) === 'latest'
        && ($request->data()['page'] ?? null) === '2'
        && ($request->data()['per_page'] ?? null) === '18');
});

it('forces available marketplace browser selection even when installed status is cleared', function (): void {
    grantMarketplaceBrowserViewOnlyAccess();

    Http::fake([
        'https://marketplace.test/api/extensions*' => Http::response([
            'data' => [
                marketplaceBrowserExtensionPayload([
                    'slug' => 'seo-suite',
                    'name' => 'SEO Suite',
                    'composer_name' => 'capell-app/seo-suite',
                ]),
            ],
            'links' => ['next' => null],
        ]),
    ]);

    Livewire::test(MarketplaceExtensionsBrowser::class)
        ->set('tableFilters.installed_status.value', '')
        ->call('loadMarketplaceResults')
        ->assertSee('SEO Suite');

    Http::assertSent(fn ($request): bool => str_starts_with((string) $request->url(), 'https://marketplace.test/api/extensions?')
        && ! array_key_exists('installed_status', $request->data()));
});

it('hides locally installed extensions by default in the marketplace browser', function (): void {
    grantMarketplaceBrowserViewOnlyAccess();

    CapellCore::registerPackage('capell-app/installed-suite', version: '1.0.0');
    CapellCore::forcePackageInstalled('capell-app/installed-suite');

    Http::fake([
        'https://marketplace.test/api/extensions*' => Http::response([
            'data' => [
                marketplaceBrowserExtensionPayload([
                    'slug' => 'installed-suite',
                    'name' => 'Installed Suite',
                    'composer_name' => 'capell-app/installed-suite',
                ]),
                marketplaceBrowserExtensionPayload([
                    'slug' => 'seo-suite',
                    'name' => 'SEO Suite',
                    'composer_name' => 'capell-app/seo-suite',
                ]),
            ],
            'links' => ['next' => null],
        ]),
    ]);

    Livewire::test(MarketplaceExtensionsBrowser::class)
        ->call('loadMarketplaceResults')
        ->assertDontSee('Installed Suite')
        ->assertDontSee(__('capell-marketplace::marketplace.selection.already_installed'))
        ->assertSee('SEO Suite');

    Http::assertSent(fn ($request): bool => str_starts_with((string) $request->url(), 'https://marketplace.test/api/extensions?')
        && ! array_key_exists('installed_status', $request->data())
        && str_contains((string) ($request->data()['installed_composer_names'] ?? ''), 'capell-app/installed-suite'));
});

it('shows locally installed themes as already installed in the locked theme marketplace browser', function (): void {
    grantMarketplaceBrowserViewOnlyAccess();

    CapellCore::registerPackage('capell-theme/installed-theme', version: '1.0.0');
    CapellCore::forcePackageInstalled('capell-theme/installed-theme');

    Http::fake([
        'https://marketplace.test/api/extensions*' => Http::response([
            'data' => [
                marketplaceBrowserExtensionPayload([
                    'slug' => 'installed-theme',
                    'name' => 'Installed Theme',
                    'composer_name' => 'capell-theme/installed-theme',
                    'kind' => 'theme',
                ]),
                marketplaceBrowserExtensionPayload([
                    'slug' => 'available-theme',
                    'name' => 'Available Theme',
                    'composer_name' => 'capell-theme/available-theme',
                    'kind' => 'theme',
                ]),
            ],
            'links' => ['next' => null],
        ]),
    ]);

    Livewire::test(MarketplaceExtensionsBrowser::class, ['lockedKind' => 'theme'])
        ->call('loadMarketplaceResults')
        ->assertDontSee('Installed Theme')
        ->assertDontSee(__('capell-marketplace::marketplace.selection.already_installed'))
        ->assertSee('Available Theme');

    Http::assertSent(fn ($request): bool => str_starts_with((string) $request->url(), 'https://marketplace.test/api/extensions?')
        && ($request->data()['kind'] ?? null) === 'theme'
        && ! array_key_exists('installed_status', $request->data())
        && str_contains((string) ($request->data()['installed_composer_names'] ?? ''), 'capell-theme/installed-theme'));
});

it('renders free tier themes without stale paid catalogue prices', function (): void {
    grantMarketplaceBrowserViewOnlyAccess();

    Http::fake([
        'https://marketplace.test/api/extensions*' => Http::response([
            'data' => [
                marketplaceBrowserExtensionPayload([
                    'slug' => 'local-services-theme',
                    'name' => 'Local Services Theme',
                    'composer_name' => 'capell-app/theme-local-services',
                    'kind' => 'theme',
                    'price_cents' => 4900,
                    'is_paid' => true,
                    'product_tier' => 'free',
                ]),
            ],
            'links' => ['next' => null],
        ]),
    ]);

    Livewire::test(MarketplaceExtensionsBrowser::class, ['lockedKind' => 'theme'])
        ->call('loadMarketplaceResults')
        ->assertSee('Local Services Theme')
        ->assertSee(__('capell-marketplace::marketplace.install.free'))
        ->assertDontSee('$49.00');
});

it('normalizes stale dependency composer names before grouped install review', function (): void {
    grantMarketplaceBrowserManagementAccess();

    Http::fake([
        'https://marketplace.test/api/extensions?*' => Http::response([
            'data' => [
                marketplaceBrowserExtensionPayload([
                    'slug' => 'publisher-tools',
                    'name' => 'Publisher Tools',
                    'composer_name' => 'capell-app/publisher-tools',
                    'dependencies' => ['requires' => ['capell-app/forms']],
                ]),
                marketplaceBrowserExtensionPayload([
                    'slug' => 'forms',
                    'name' => 'Forms',
                    'composer_name' => 'capell-app/forms',
                ]),
            ],
            'links' => ['next' => null],
        ]),
        'https://marketplace.test/api/extensions/by-composer*' => Http::response([
            'data' => [
                marketplaceBrowserExtensionPayload([
                    'slug' => 'forms',
                    'name' => 'Forms',
                    'composer_name' => 'capell-app/forms',
                ]),
            ],
        ]),
    ]);

    Livewire::test(MarketplaceExtensionsBrowser::class)
        ->call('loadMarketplaceResults')
        ->call('toggleMarketplaceSelection', 'capell-app/publisher-tools')
        ->call('showMarketplaceInstallReview')
        ->assertSet('marketplaceStep', 'review')
        ->assertSee('Forms')
        ->assertSee(trans_choice('capell-marketplace::marketplace.selection.final_install_count_button', 2, ['count' => 2]))
        ->assertDontSee(__('capell-marketplace::marketplace.selection.missing_dependencies', [
            'dependencies' => 'capell-app/forms',
        ]));
});

it('hides installer and marketplace packages from the marketplace browser', function (): void {
    grantMarketplaceBrowserViewOnlyAccess();

    Http::fake([
        'https://marketplace.test/api/extensions*' => Http::response([
            'data' => [
                marketplaceBrowserExtensionPayload([
                    'slug' => 'installer',
                    'name' => 'Installer',
                    'composer_name' => 'capell-app/installer',
                ]),
                marketplaceBrowserExtensionPayload([
                    'slug' => 'marketplace',
                    'name' => 'Capell Marketplace',
                    'composer_name' => 'capell-app/marketplace',
                ]),
                marketplaceBrowserExtensionPayload([
                    'slug' => 'capell-app-plugins',
                    'name' => 'Capell Marketplace',
                    'composer_name' => 'capell-app/plugins',
                ]),
                marketplaceBrowserExtensionPayload([
                    'slug' => 'seo-suite',
                    'name' => 'SEO Suite',
                    'composer_name' => 'capell-app/seo-suite',
                ]),
            ],
            'links' => ['next' => null],
        ]),
    ]);

    Livewire::test(MarketplaceExtensionsBrowser::class)
        ->call('loadMarketplaceResults')
        ->assertDontSee('Installer')
        ->assertDontSee('Capell Marketplace')
        ->assertSee('SEO Suite');
});

it('fills the first marketplace browser page after local hidden extensions are removed', function (): void {
    grantMarketplaceBrowserViewOnlyAccess();

    Http::fake(function ($request) {
        $page = (string) ($request->data()['page'] ?? '1');

        if ($page === '2') {
            return Http::response([
                'data' => array_map(
                    fn (int $number): array => marketplaceBrowserExtensionPayload([
                        'slug' => 'visible-page-two-' . $number,
                        'name' => 'Visible Page Two ' . $number,
                        'composer_name' => 'capell-app/visible-page-two-' . $number,
                    ]),
                    range(1, 5),
                ),
                'links' => ['next' => null],
                'meta' => [
                    'current_page' => 2,
                    'per_page' => 18,
                    'total' => 14,
                ],
            ]);
        }

        return Http::response([
            'data' => [
                marketplaceBrowserExtensionPayload([
                    'slug' => 'installer',
                    'name' => 'Installer',
                    'composer_name' => 'capell-app/installer',
                ]),
                marketplaceBrowserExtensionPayload([
                    'slug' => 'marketplace',
                    'name' => 'Marketplace',
                    'composer_name' => 'capell-app/marketplace',
                ]),
                marketplaceBrowserExtensionPayload([
                    'slug' => 'plugins',
                    'name' => 'Plugins',
                    'composer_name' => 'capell-app/plugins',
                ]),
                marketplaceBrowserExtensionPayload([
                    'slug' => 'visible-one',
                    'name' => 'Visible One',
                    'composer_name' => 'capell-app/visible-one',
                ]),
                marketplaceBrowserExtensionPayload([
                    'slug' => 'visible-two',
                    'name' => 'Visible Two',
                    'composer_name' => 'capell-app/visible-two',
                ]),
                marketplaceBrowserExtensionPayload([
                    'slug' => 'visible-three',
                    'name' => 'Visible Three',
                    'composer_name' => 'capell-app/visible-three',
                ]),
                marketplaceBrowserExtensionPayload([
                    'slug' => 'visible-four',
                    'name' => 'Visible Four',
                    'composer_name' => 'capell-app/visible-four',
                ]),
                marketplaceBrowserExtensionPayload([
                    'slug' => 'hidden-marketplace-copy',
                    'name' => 'Marketplace Copy',
                    'composer_name' => 'capell-app/marketplace',
                ]),
                marketplaceBrowserExtensionPayload([
                    'slug' => 'hidden-installer-copy',
                    'name' => 'Installer Copy',
                    'composer_name' => 'capell-app/installer',
                ]),
            ],
            'links' => ['next' => 'https://marketplace.test/api/extensions?page=2'],
            'meta' => [
                'current_page' => 1,
                'per_page' => 18,
                'total' => 14,
            ],
        ]);
    });

    $records = resolve(MarketplaceCatalogueRecordProvider::class)->records();

    expect($records)->toHaveCount(9)
        ->and(collect($records)->pluck('name')->all())->toContain('Visible One', 'Visible Page Two 5')
        ->and(collect($records)->pluck('composer_name')->all())->not->toContain('capell-app/installer', 'capell-app/marketplace', 'capell-app/plugins');

    Http::assertSent(fn ($request): bool => str_starts_with((string) $request->url(), 'https://marketplace.test/api/extensions?')
        && ($request->data()['page'] ?? null) === '2');
});

it('renders safe marketplace card fallbacks when author and ratings are missing', function (): void {
    grantMarketplaceBrowserViewOnlyAccess();

    Http::fake([
        'https://marketplace.test/api/extensions*' => Http::response([
            'data' => [
                marketplaceBrowserExtensionPayload([
                    'slug' => 'capell-extension',
                    'name' => 'Capell Extension',
                    'composer_name' => 'capell-app/capell-extension',
                ]),
                marketplaceBrowserExtensionPayload([
                    'slug' => 'vendor-extension',
                    'name' => 'Vendor Extension',
                    'composer_name' => 'vendor/extension',
                ]),
            ],
            'links' => ['next' => null],
        ]),
    ]);

    Livewire::test(MarketplaceExtensionsBrowser::class)
        ->call('loadMarketplaceResults')
        ->assertSee(__('capell-marketplace::marketplace.card.no_ratings'))
        ->assertSee('★')
        ->assertSee('Capell')
        ->assertSee(__('capell-marketplace::marketplace.card.unknown_author'));
});

it('redirects account verification required grouped installs through a hosted install flow', function (): void {
    grantMarketplaceBrowserManagementAccess();

    config([
        'app.url' => 'http://capell-ruby.test',
        'capell-marketplace.instance.id' => 'instance-123',
        'capell-marketplace.marketplace.webhook_secret' => 'test-secret',
    ]);

    MarketplaceInstance::query()->create([
        'instance_id' => 'instance-123',
        'signing_secret_encrypted' => 'test-secret',
        'connection_mode' => MarketplaceConnectionMode::AccountLinked,
        'account_id' => 'acct_123',
        'last_heartbeat_at' => now(),
    ]);

    $extensionPayload = marketplaceBrowserExtensionPayload([
        'slug' => 'protected-suite',
        'name' => 'Protected Suite',
        'composer_name' => 'capell-app/protected-suite',
        'catalogue_role' => 'extension',
        'maturity' => 'beta',
        'maturity_label' => 'Beta',
        'included_with_capell_all' => true,
        'install_eligibility' => [
            'state' => 'blocked',
            'block_reason' => 'email_verification_required',
        ],
        'install_options' => [
            ['key' => 'starter_content', 'type' => 'checkbox', 'label' => 'Starter content', 'default' => true],
        ],
    ]);

    Http::fake([
        'https://marketplace.test/api/extensions?*' => Http::response([
            'data' => [$extensionPayload],
            'links' => ['next' => null],
        ]),
        'https://marketplace.test/api/extensions/protected-suite' => Http::response([
            'data' => $extensionPayload,
        ]),
        'https://marketplace.test/api/marketplace/install-flows' => Http::response([
            'data' => [
                'contract_version' => 2,
                'flow_id' => 'mif_123',
                'approval_url' => 'https://marketplace.test/marketplace/install-flows/mif_123',
                'quote' => [
                    'currency' => 'usd',
                    'price_cents' => 0,
                    'extensions' => [
                        [
                            'slug' => 'protected-suite',
                            'composer_name' => 'capell-app/protected-suite',
                            'name' => 'Protected Suite',
                            'kind' => 'package',
                            'price_cents' => 0,
                        ],
                    ],
                ],
                'expires_at' => now()->addMinutes(10)->toIso8601String(),
            ],
        ], 201),
    ]);

    Livewire::test(MarketplaceExtensionsBrowser::class)
        ->call('loadMarketplaceResults')
        ->assertSee('Protected Suite')
        ->call('toggleMarketplaceSelection', 'capell-app/protected-suite')
        ->assertSet('selectedMarketplaceComposerNames', ['capell-app/protected-suite'])
        ->call('showMarketplaceInstallReview')
        ->assertSet('selectedMarketplaceInstallOptions.starter_content', true)
        ->assertSee(trans_choice('capell-marketplace::marketplace.selection.final_install_count_button', 1, ['count' => 1]))
        ->assertSee(__('capell-marketplace::marketplace.selection.premium_notice'))
        ->assertSee(__('capell-marketplace::marketplace.release_status.beta'))
        ->assertSee(__('capell-marketplace::marketplace.selection.beta_acknowledgement_label'))
        ->set('betaMarketplaceExtensionsAcknowledged', true)
        ->set('installReviewedMarketplaceExtensionsConfirmed', true)
        ->call('installReviewedMarketplaceExtensions')
        ->assertRedirect('https://marketplace.test/marketplace/install-flows/mif_123');

    expect(MarketplaceInstallAttempt::query()->count())->toBe(0);

    $session = MarketplaceInstallFlowSession::query()->sole();

    expect($session->remote_flow_id)->toBe('mif_123')
        ->and($session->status)->toBe(MarketplaceInstallFlowSessionStatus::Redirected)
        ->and($session->quoted_extensions[0]['composer_name'] ?? null)->toBe('capell-app/protected-suite');

    Http::assertSent(fn ($request): bool => (string) $request->url() === 'https://marketplace.test/api/marketplace/install-flows'
        && ($request->data()['contract_version'] ?? null) === 2
        && ($request->data()['selected_extensions'][0]['composer_name'] ?? null) === 'capell-app/protected-suite'
        && ($request->data()['install_options']['beta_acknowledged'] ?? null) === true
        && ($request->data()['install_options']['capell-app/protected-suite'] ?? null) === ['starter_content' => true]
        && ($request->data()['install_options']['protected-suite'] ?? null) === ['starter_content' => true]);
});

it('detects transitive beta dependencies in install review', function (): void {
    grantMarketplaceBrowserManagementAccess();

    Http::fake([
        'https://marketplace.test/api/extensions*' => Http::response([
            'data' => [
                marketplaceBrowserExtensionPayload([
                    'slug' => 'publishing-studio',
                    'name' => 'Publishing Studio',
                    'composer_name' => 'capell-app/publishing-studio',
                    'catalogue_role' => 'extension',
                    'maturity' => 'stable',
                    'maturity_label' => 'Released',
                    'included_with_capell_all' => true,
                    'dependencies' => [
                        'requires' => ['capell-app/migration-assistant'],
                    ],
                ]),
                marketplaceBrowserExtensionPayload([
                    'slug' => 'migration-assistant',
                    'name' => 'Migration Assistant',
                    'composer_name' => 'capell-app/migration-assistant',
                    'catalogue_role' => 'extension',
                    'maturity' => 'beta',
                    'maturity_label' => 'Beta',
                    'included_with_capell_all' => true,
                ]),
            ],
            'links' => ['next' => null],
        ]),
    ]);

    Livewire::test(MarketplaceExtensionsBrowser::class)
        ->call('loadMarketplaceResults')
        ->call('toggleMarketplaceSelection', 'capell-app/publishing-studio')
        ->call('showMarketplaceInstallReview')
        ->assertSee('Migration Assistant')
        ->assertSee('capell-app/migration-assistant')
        ->assertSee(__('capell-marketplace::marketplace.release_status.beta'))
        ->assertSee(__('capell-marketplace::marketplace.selection.beta_dependency_acknowledgement_help', [
            'dependencies' => 'capell-app/migration-assistant',
        ]))
        ->assertSee(__('capell-marketplace::marketplace.selection.beta_acknowledgement_label'));
});

it('does not show beta acknowledgement for the released Foundation theme', function (): void {
    grantMarketplaceBrowserManagementAccess();

    Http::fake([
        'https://marketplace.test/api/extensions*' => Http::response([
            'data' => [marketplaceBrowserExtensionPayload([
                'slug' => 'theme-foundation',
                'name' => 'Foundation Theme',
                'composer_name' => 'capell-app/theme-foundation',
                'kind' => 'theme',
                'catalogue_role' => 'extension',
                'maturity' => 'stable',
                'maturity_label' => 'Released',
                'included_with_capell_all' => true,
            ])],
            'links' => ['next' => null],
        ]),
    ]);

    Livewire::test(MarketplaceExtensionsBrowser::class)
        ->call('loadMarketplaceResults')
        ->call('toggleMarketplaceSelection', 'capell-app/theme-foundation')
        ->call('showMarketplaceInstallReview')
        ->assertSee('Foundation Theme')
        ->assertDontSee(__('capell-marketplace::marketplace.selection.beta_acknowledgement_label'));
});

it('applies the marketplace author filter from the card action', function (): void {
    grantMarketplaceBrowserViewOnlyAccess();

    Http::fake([
        'https://marketplace.test/api/extensions*' => Http::response([
            'data' => [
                marketplaceBrowserExtensionPayload([
                    'author_name' => 'Capell Labs',
                    'author_slug' => 'capell-labs',
                ]),
            ],
            'links' => ['next' => null],
        ]),
    ]);

    Livewire::test(MarketplaceExtensionsBrowser::class)
        ->call('loadMarketplaceResults')
        ->call('filterByMarketplaceAuthor', 'capell-labs', 'Capell Labs')
        ->assertSet('tableFilters.author.author', 'Capell Labs')
        ->assertSet('tableFilters.author.author_slug', 'capell-labs');

    Http::assertSent(fn ($request): bool => str_starts_with((string) $request->url(), 'https://marketplace.test/api/extensions?')
        && ($request->data()['author'] ?? null) === 'capell-labs');
});

it('builds marketplace table records from filtered marketplace listings', function (): void {
    grantMarketplaceBrowserViewOnlyAccess();

    Http::fake([
        'https://marketplace.test/api/extensions*' => Http::response([
            'data' => [
                [
                    'slug' => 'seo-audit',
                    'name' => 'SEO Audit',
                    'composer_name' => 'capell-app/seo-audit',
                    'kind' => 'tool',
                    'description' => 'Audit public pages.',
                    'price_cents' => 1200,
                    'is_paid' => true,
                    'latest_version' => '2.1.0',
                    'released_at' => '2026-05-01T10:00:00+00:00',
                    'documentation_url' => 'https://marketplace.test/docs/seo-audit',
                    'requires_confirmation' => true,
                    'install_confirmation' => [
                        'summary' => 'Installs SEO checks.',
                    ],
                    'install_options' => [
                        ['key' => 'starter_checks', 'type' => 'checkbox', 'label' => 'Starter checks'],
                    ],
                    'product_group' => 'Capell Growth',
                    'publisher_name' => 'Capell Labs',
                    'publisher_slug' => 'capell-labs',
                    'rating_average' => 4.5,
                    'ratings_count' => 18,
                    'categories' => ['seo', 'custom_tools'],
                    'capabilities' => [
                        'settings' => true,
                        ['slug' => 'search'],
                    ],
                    'publisher_verified' => true,
                    'security_reviewed' => true,
                ],
            ],
            'links' => ['next' => null],
        ]),
    ]);

    $records = resolve(MarketplaceCatalogueRecordProvider::class)->records(
        search: 'seo',
        filters: [
            'kind' => ['value' => 'tool'],
            'sort' => ['value' => 'latest'],
            'category' => ['value' => 'seo'],
            'author' => ['author' => 'capell-labs'],
            'capability' => ['values' => ['settings', 'invalid', 'search']],
            'free_only' => ['isActive' => true],
            'installed_status' => ['value' => false],
            'price' => [
                'price_min' => '10.50',
                'price_max' => '99',
            ],
            'compatibility' => [
                'capell_version' => '4.0.0',
                'laravel_version' => '12.0.0',
                'filament_version' => '4.0.0',
                'livewire_version' => '3.0.0',
            ],
        ],
    );

    expect($records)->toHaveCount(1)
        ->and($records[0])->toMatchArray([
            'key' => 'seo-audit',
            'slug' => 'seo-audit',
            'name' => 'SEO Audit',
            'composer_name' => 'capell-app/seo-audit',
            'kind' => 'tool',
            'product_group' => 'Capell Growth',
            'description' => 'Audit public pages.',
            'price_cents' => 1200,
            'price_label' => '$12.00',
            'is_paid' => true,
            'latest_version' => '2.1.0',
            'released_at_label' => 'May 1, 2026',
            'author_name' => 'Capell Labs',
            'author_filter' => 'capell-labs',
            'rating_average' => 4.5,
            'rating_stars' => ['full', 'full', 'full', 'full', 'half'],
            'ratings_count_label' => '18 ratings',
            'documentation_url' => 'https://marketplace.test/docs/seo-audit',
            'requires_confirmation' => true,
            'install_confirmation' => ['summary' => 'Installs SEO checks.'],
            'install_options' => [
                ['key' => 'starter_checks', 'type' => 'checkbox', 'label' => 'Starter checks'],
            ],
            'is_publisher_verified' => true,
            'is_security_reviewed' => true,
            'is_installed' => false,
            'has_update_available' => false,
            'is_compatible' => true,
            'compatibility_warnings' => [],
        ])
        ->and($records[0]['category_labels'])->toContain('SEO', 'Custom Tools')
        ->and($records[0]['capability_labels'])->toContain('Settings', 'Search');

    Http::assertSent(fn ($request): bool => str_starts_with((string) $request->url(), 'https://marketplace.test/api/extensions?')
        && $request->data()['search'] === 'seo'
        && $request->data()['kind'] === 'tool'
        && $request->data()['free'] === '1'
        && $request->data()['sort'] === 'latest'
        && $request->data()['min_price_cents'] === '1050'
        && $request->data()['max_price_cents'] === '9900'
        && $request->data()['category'] === 'seo'
        && $request->data()['author'] === 'capell-labs'
        && $request->data()['capabilities'] === 'settings,search'
        && ! array_key_exists('installed_status', $request->data())
        && $request->data()['page'] === '1'
        && $request->data()['per_page'] === '18'
        && $request->data()['capell_version'] === '4.0.0'
        && $request->data()['laravel_version'] === '12.0.0'
        && $request->data()['filament_version'] === '4.0.0'
        && $request->data()['livewire_version'] === '3.0.0');
});

it('marks marketplace browse as unavailable when the catalogue request fails', function (): void {
    Http::fake([
        'https://marketplace.test/api/extensions*' => Http::response(['message' => 'Unavailable'], 503),
    ]);

    $unavailableDescription = __('capell-marketplace::marketplace.filters.unavailable_description') . ' ' . __('capell-marketplace::marketplace.filters.unavailable_reason', ['reason' => 'Unavailable']);
    $provider = resolve(MarketplaceCatalogueRecordProvider::class);
    $catalogueTable = resolve(MarketplaceCatalogueTable::class);

    $records = $provider->records(
        search: ' seo ',
        filters: [
            'kind' => ['value' => 'invalid-kind'],
            'sort' => ['value' => 'invalid-sort'],
            'category' => ['value' => 'invalid-category'],
            'capability' => ['values' => ['invalid-capability']],
            'price' => [
                'price_min' => '-5',
                'price_max' => 'not-money',
            ],
        ],
    );

    expect($records)->toBeEmpty()
        ->and($provider->marketplaceBrowseUnavailable())->toBeTrue()
        ->and($catalogueTable->marketplaceEmptyStateHeading())->toBe(__('capell-marketplace::marketplace.filters.unavailable_heading'))
        ->and($catalogueTable->marketplaceEmptyStateDescription())->toBe($unavailableDescription);
});

it('marks installed marketplace records with update and compatibility state', function (): void {
    grantMarketplaceBrowserViewOnlyAccess();
    CapellCore::registerPackage('capell-app/seo-suite', version: '2.0.0');
    CapellCore::forcePackageInstalled('capell-app/seo-suite');

    Http::fake([
        'https://marketplace.test/api/extensions*' => Http::response([
            'data' => [
                marketplaceBrowserExtensionPayload([
                    'slug' => 'seo-suite',
                    'name' => 'SEO Suite',
                    'composer_name' => 'capell-app/seo-suite',
                    'latest_version' => '2.1.0',
                    'laravel_version_constraint' => '<1.0',
                    'capabilities' => [
                        'settings' => true,
                        'cache' => false,
                        ['key' => 'bulk_tools'],
                        'customReports',
                    ],
                    'categories' => ['seo', 'bespoke_category'],
                ]),
            ],
            'links' => ['next' => null],
        ]),
    ]);

    $installedRecords = resolve(MarketplaceCatalogueRecordProvider::class)->records(
        filters: [
            'installed_status' => ['value' => true],
        ],
    );

    expect($installedRecords)->toHaveCount(1)
        ->and($installedRecords[0])->toMatchArray([
            'slug' => 'seo-suite',
            'composer_name' => 'capell-app/seo-suite',
            'is_installed' => true,
            'installed_version' => '2.0.0',
            'has_update_available' => true,
            'is_compatible' => false,
        ])
        ->and($installedRecords[0]['category_labels'])->toContain('SEO', 'Bespoke Category')
        ->and($installedRecords[0]['capability_labels'])->toContain('Settings', 'Bulk Tools', 'Custom Reports')
        ->and($installedRecords[0]['capability_labels'])->not->toContain('Cache')
        ->and($installedRecords[0]['compatibility_warnings'])->not->toBeEmpty();

    Http::assertSent(fn ($request): bool => str_starts_with((string) $request->url(), 'https://marketplace.test/api/extensions?')
        && $request->data()['installed_status'] === 'installed'
        && str_contains((string) $request->data()['installed_composer_names'], 'capell-app/seo-suite'));
});

it('can build marketplace records and table filters for a locked browser kind', function (): void {
    grantMarketplaceBrowserViewOnlyAccess();

    Http::fake([
        'https://marketplace.test/api/extensions*' => Http::response([
            'data' => [
                marketplaceBrowserExtensionPayload([
                    'slug' => 'agency-theme',
                    'name' => 'Agency Theme',
                    'composer_name' => 'capell-app/theme-agency',
                    'kind' => 'theme',
                ]),
            ],
            'links' => ['next' => null],
        ]),
    ]);

    $records = resolve(MarketplaceCatalogueRecordProvider::class)->records(
        filters: [
            'kind' => ['value' => 'tool'],
        ],
        lockedKind: 'theme',
    );

    $filterMethod = new ReflectionMethod(MarketplaceCatalogueTable::class, 'getMarketplaceTableFilters');

    $filterNames = collect($filterMethod->invoke(resolve(MarketplaceCatalogueTable::class), 'theme'))
        ->map(fn (object $filter): ?string => filamentObjectName($filter))
        ->filter()
        ->values()
        ->all();

    expect($records)->toHaveCount(1)
        ->and($records[0])->toMatchArray([
            'slug' => 'agency-theme',
            'composer_name' => 'capell-app/theme-agency',
            'kind' => 'theme',
            'is_installed' => false,
            'is_compatible' => true,
        ])
        ->and($filterNames)->not->toContain('kind');

    Http::assertSent(fn ($request): bool => str_starts_with((string) $request->url(), 'https://marketplace.test/api/extensions?')
        && $request->data()['kind'] === 'theme'
        && ! array_key_exists('installed_status', $request->data()));
});

it('provides a package-owned livewire browser component', function (): void {
    $componentReflection = new ReflectionClass(MarketplaceExtensionsBrowser::class);

    expect($componentReflection->getMethod('table')->hasReturnType())->toBeTrue()
        ->and((string) $componentReflection->getProperty('lockedKind')->getType())->toBe('?string');
});

it('delegates Marketplace selection reshaping to a typed package policy', function (): void {
    $handle = new ReflectionMethod(BuildMarketplaceSelectionReviewAction::class, 'handle');
    $input = $handle->getParameters()[0]->getType();

    expect((string) $input)->toBe(MarketplaceSelectionInputData::class)
        ->and((string) $handle->getReturnType())->toBe(MarketplaceSelectionReviewData::class)
        ->and((string) new ReflectionMethod(
            MarketplaceExtensionsBrowser::class,
            'marketplaceSelectionReview',
        )->getReturnType())->toBe('array');
});

it('does not expose per-extension marketplace install actions', function (): void {
    grantMarketplaceBrowserViewOnlyAccess();

    Http::fake([
        'https://marketplace.test/api/extensions*' => Http::response([
            'data' => [
                marketplaceBrowserExtensionPayload([
                    'slug' => 'seo-suite',
                    'name' => 'SEO Suite',
                    'composer_name' => 'capell-app/seo-suite',
                ]),
            ],
            'links' => ['next' => null],
        ]),
    ]);

    resolve(MarketplaceCatalogueRecordProvider::class)->records();

    expect(new ReflectionClass(MarketplaceCatalogueTable::class)->hasMethod('getMarketplaceTableActions'))
        ->toBeFalse();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function marketplaceBrowserExtensionPayload(array $overrides = []): array
{
    return [
        'slug' => 'example-extension',
        'name' => 'Example Extension',
        'composer_name' => 'capell-app/example-extension',
        'kind' => 'package',
        'description' => 'Example extension.',
        'price_cents' => 0,
        'is_paid' => false,
        'latest_version' => '1.0.0',
        ...$overrides,
    ];
}

/**
 * Put the browser on the review step with one selected extension, so a test can
 * assert what a host of a given capability is offered before it confirms.
 */
function reviewMarketplaceBrowserWithOneSelection(): Testable
{
    Http::fake([
        'https://marketplace.test/api/extensions?*' => Http::response([
            'data' => [
                marketplaceBrowserExtensionPayload([
                    'slug' => 'seo-suite',
                    'name' => 'SEO Suite',
                    'composer_name' => 'capell-app/seo-suite',
                ]),
            ],
            'links' => ['next' => null],
        ]),
        '*' => Http::response(['data' => []]),
    ]);

    return Livewire::test(MarketplaceExtensionsBrowser::class)
        ->call('loadMarketplaceResults')
        ->call('toggleMarketplaceSelection', 'capell-app/seo-suite')
        ->call('showMarketplaceInstallReview')
        ->assertSet('marketplaceStep', 'review');
}

it('summarises install readiness on a host that can install automatically', function (): void {
    grantMarketplaceBrowserManagementAccess();
    fakeMarketplaceEnvironmentReadiness(capability: MarketplaceInstallCapability::Automated);

    reviewMarketplaceBrowserWithOneSelection()
        ->assertDontSeeHtml('data-capell-marketplace-readiness-banner')
        ->assertSeeHtml('data-capell-marketplace-readiness-summary')
        ->assertSee(__('capell-marketplace::marketplace.install.default_confirmation'))
        ->assertSee(__('capell-marketplace::marketplace.selection.confirm_download_install_label'));
});

it('explains the host and names its capability when an install is not automatic', function (): void {
    grantMarketplaceBrowserManagementAccess();
    fakeMarketplaceEnvironmentReadiness(
        capability: MarketplaceInstallCapability::ManualOnly,
        processExecutionStatus: MarketplaceReadinessStatus::Fail,
    );

    reviewMarketplaceBrowserWithOneSelection()
        ->assertSeeHtml('data-capell-marketplace-readiness-banner')
        ->assertSeeHtml('data-capell-marketplace-readiness-summary')
        ->assertSeeHtml('data-capell-marketplace-readiness-capability="manual_only"')
        ->assertSeeHtml('data-capell-marketplace-readiness-check="process_execution"');
});

it('offers install instructions instead of a confirmation on a manual-only host', function (): void {
    grantMarketplaceBrowserManagementAccess();
    fakeMarketplaceEnvironmentReadiness(
        capability: MarketplaceInstallCapability::ManualOnly,
        processExecutionStatus: MarketplaceReadinessStatus::Fail,
    );

    reviewMarketplaceBrowserWithOneSelection()
        ->assertSeeHtml('data-capell-marketplace-manual-install-cta')
        ->assertDontSee(__('capell-marketplace::marketplace.selection.confirm_download_install_label'))
        ->assertSee(__('capell-marketplace::marketplace.readiness.manual_install_cta_heading'));
});

it('refuses to queue an install on a manual-only host even when confirmation is forced', function (): void {
    grantMarketplaceBrowserManagementAccess();
    fakeMarketplaceEnvironmentReadiness(
        capability: MarketplaceInstallCapability::ManualOnly,
        processExecutionStatus: MarketplaceReadinessStatus::Fail,
    );

    // The checkbox is not rendered, so this is the crafted-request case: the
    // gate has to hold on the server, not only in the markup.
    reviewMarketplaceBrowserWithOneSelection()
        ->set('installReviewedMarketplaceExtensionsConfirmed', true)
        ->call('installReviewedMarketplaceExtensions');

    expect(MarketplaceInstallAttempt::query()->count())->toBe(0);
});

it('shows a per-card progress pill for each operation the modal started', function (): void {
    grantMarketplaceBrowserManagementAccess();

    $runningAttempt = MarketplaceInstallAttempt::query()->create([
        'composer_name' => 'capell-app/seo-suite',
        'extension_slug' => 'seo-suite',
        'extension_name' => 'SEO Suite',
        'kind' => 'tool',
        'status' => MarketplaceInstallIntentStatus::Running,
        'composer_command' => 'composer require capell-app/seo-suite',
        'current_stage' => MarketplaceInstallFailureStage::Composer->value,
        'progress_current' => 1,
        'progress_total' => 5,
        'queued_at' => now(),
    ]);

    Livewire::test(MarketplaceExtensionsBrowser::class)
        ->set('marketplaceResultsFetched', true)
        ->set('marketplaceStep', 'progress')
        ->set('activeMarketplaceInstallAttemptIds', [(int) $runningAttempt->getKey()])
        ->assertSee('data-capell-marketplace-install-progress', false)
        ->assertSee('data-capell-marketplace-progress-pill="capell-app/seo-suite"', false)
        ->assertSee('data-capell-marketplace-progress-stage="composer"', false)
        ->assertSee('data-capell-marketplace-progress-status="running"', false)
        // The stage the operator is watching, named in their language rather
        // than left as a raw enum value.
        ->assertSee(__('capell-marketplace::marketplace.progress.stage_composer'))
        // And the way out to the full timeline, still available.
        ->assertSee('data-capell-marketplace-view-operations', false);
});

it('reports each stage of a running install through the progress pill', function (): void {
    grantMarketplaceBrowserManagementAccess();

    $attempt = MarketplaceInstallAttempt::query()->create([
        'composer_name' => 'capell-app/seo-suite',
        'extension_slug' => 'seo-suite',
        'extension_name' => 'SEO Suite',
        'kind' => 'tool',
        'status' => MarketplaceInstallIntentStatus::Running,
        'composer_command' => 'composer require capell-app/seo-suite',
        'current_stage' => MarketplaceInstallFailureStage::HealthCheck->value,
        'progress_current' => 4,
        'progress_total' => 5,
        'queued_at' => now(),
    ]);

    $component = Livewire::test(MarketplaceExtensionsBrowser::class)
        ->set('activeMarketplaceInstallAttemptIds', [(int) $attempt->getKey()]);

    expect($component->instance()->marketplaceInstallProgress())->toBe([[
        'id' => (int) $attempt->getKey(),
        'name' => 'SEO Suite',
        'composer_name' => 'capell-app/seo-suite',
        'status' => MarketplaceInstallIntentStatus::Running->value,
        'stage' => MarketplaceInstallFailureStage::HealthCheck->value,
        'stage_label' => (string) __('capell-marketplace::marketplace.progress.stage_health_check'),
        'progress_current' => 4,
        'progress_total' => 5,
        'active' => true,
        'succeeded' => false,
        'failure_reason' => null,
    ]])
        ->and($component->instance()->hasActiveMarketplaceInstalls())->toBeTrue();

    $attempt->forceFill([
        'status' => MarketplaceInstallIntentStatus::Succeeded,
        'progress_current' => 5,
    ])->save();

    // Polling stops the moment nothing is still running.
    expect($component->instance()->hasActiveMarketplaceInstalls())->toBeFalse()
        ->and($component->instance()->marketplaceInstallProgress()[0]['stage_label'])
        ->toBe((string) __('capell-marketplace::marketplace.progress.stage_completed'));
});

it('offers to apply a theme after install, off by default, and never for a plugin', function (): void {
    grantMarketplaceBrowserManagementAccess();

    Http::fake([
        'https://marketplace.test/api/extensions?*' => Http::response([
            'data' => [
                marketplaceBrowserExtensionPayload([
                    'slug' => 'aurora-theme',
                    'name' => 'Aurora',
                    'composer_name' => 'capell-app/aurora-theme',
                    'kind' => 'theme',
                    'latest_version' => '1.0.0',
                ]),
            ],
            'links' => ['next' => null],
        ]),
        'https://marketplace.test/api/extensions/by-composer*' => Http::response([
            'data' => [
                marketplaceBrowserExtensionPayload([
                    'slug' => 'aurora-theme',
                    'name' => 'Aurora',
                    'composer_name' => 'capell-app/aurora-theme',
                    'kind' => 'theme',
                    'latest_version' => '1.0.0',
                ]),
            ],
        ]),
    ]);

    Livewire::test(MarketplaceExtensionsBrowser::class)
        ->call('loadMarketplaceResults')
        ->call('toggleMarketplaceSelection', 'capell-app/aurora-theme')
        ->call('showMarketplaceInstallReview')
        // Applying a theme changes what every visitor sees, so it is opt-in.
        ->assertSet('activateMarketplaceThemesAfterInstall', false)
        ->assertSee('data-capell-marketplace-activate-theme-option', false)
        ->assertSee(__('capell-marketplace::marketplace.selection.activate_theme_after_install_label'));
});

it('carries the activate-after-install choice from the review screen into the theme install intent', function (bool $activate): void {
    // Driven through the real Livewire → install path on purpose. Calling
    // RecordThemeInstallIntentAction directly would prove only that the action
    // stores what it is handed, and would not notice the checkbox being dropped
    // on the way there — which is exactly what it was doing.
    grantMarketplaceBrowserManagementAccess();
    Queue::fake();

    $themePayload = marketplaceBrowserExtensionPayload([
        'slug' => 'aurora-theme',
        'name' => 'Aurora',
        'composer_name' => 'capell-app/aurora-theme',
        'kind' => 'theme',
        'latest_version' => '1.0.0',
    ]);

    Http::fake([
        'https://marketplace.test/api/extensions/aurora-theme' => Http::response(['data' => $themePayload]),
        'https://marketplace.test/api/extensions/by-composer*' => Http::response(['data' => [$themePayload]]),
        'https://marketplace.test/api/extensions*' => Http::response([
            'data' => [$themePayload],
            'links' => ['next' => null],
        ]),
    ]);

    Livewire::test(MarketplaceExtensionsBrowser::class)
        ->call('loadMarketplaceResults')
        ->call('toggleMarketplaceSelection', 'capell-app/aurora-theme')
        ->call('showMarketplaceInstallReview')
        ->set('activateMarketplaceThemesAfterInstall', $activate)
        ->set('installReviewedMarketplaceExtensionsConfirmed', true)
        ->call('installReviewedMarketplaceExtensions');

    $intent = MarketplaceInstallIntent::query()
        ->where('composer_name', 'capell-app/aurora-theme')
        ->sole();

    expect(data_get($intent->metadata, 'acquisition.' . RecordThemeInstallIntentAction::ACTIVATE_AFTER_INSTALL))
        ->toBe($activate);
})->with([true, false]);

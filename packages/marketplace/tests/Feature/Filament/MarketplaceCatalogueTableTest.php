<?php

declare(strict_types=1);

use Capell\Admin\Actions\Extensions\EnrichExtensionTableRecordsAction;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Marketplace\Actions\BuildMarketplaceInstallPolicyEvidenceAction;
use Capell\Marketplace\Actions\QueueMarketplaceInstallAttemptAction;
use Capell\Marketplace\Actions\WarmMarketplaceCatalogueCacheAction;
use Capell\Marketplace\Contracts\MarketplaceComposerChangePublisher;
use Capell\Marketplace\Data\ExtensionAcquisitionData;
use Capell\Marketplace\Data\ExtensionListingData;
use Capell\Marketplace\Data\MarketplaceComposerPublicationRequestData;
use Capell\Marketplace\Data\MarketplaceComposerPublicationResultData;
use Capell\Marketplace\Data\MarketplaceInstallActorData;
use Capell\Marketplace\Data\MarketplaceInstallEligibilityData;
use Capell\Marketplace\Data\MarketplaceInstallPolicyEvidenceData;
use Capell\Marketplace\Enums\MarketplaceConnectionMode;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Enums\MarketplaceInstallSource;
use Capell\Marketplace\Enums\MarketplaceInstallState;
use Capell\Marketplace\Filament\Pages\MarketplacePackageOperationsPage;
use Capell\Marketplace\Filament\Support\MarketplaceCatalogueRecordProvider;
use Capell\Marketplace\Filament\Support\MarketplaceCatalogueTable;
use Capell\Marketplace\Filament\Support\MarketplaceInstallActionPresenter;
use Capell\Marketplace\Jobs\SendMarketplaceInstallTelemetryJob;
use Capell\Marketplace\Jobs\WarmMarketplaceCatalogueCacheJob;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Capell\Marketplace\Models\MarketplaceInstallIntent;
use Capell\Marketplace\Models\MarketplaceInstance;
use Capell\Marketplace\Services\MarketplaceClient;
use Capell\Marketplace\Support\MarketplaceInstallNotifications;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Illuminate\View\ComponentAttributeBag;

uses(CreatesAdminUser::class);

beforeEach(function (): void {
    Cache::flush();
    test()->actingAsAdmin();

    config([
        'capell-marketplace.marketplace.base_url' => 'https://marketplace.test/api',
        'capell-marketplace.marketplace.cache_ttl_seconds' => 300,
    ]);
});

function ensureDeploymentPublisherTestContracts(): void
{
    app()->instance('test.marketplace.deployment-publisher-adapter', new class implements MarketplaceComposerChangePublisher
    {
        public function publish(MarketplaceComposerPublicationRequestData $request): MarketplaceComposerPublicationResultData
        {
            $result = app()->make('Capell\\Deployments\\Contracts\\PublishesComposerChanges')->publish($request);

            return new MarketplaceComposerPublicationResultData(
                pullRequestUrl: is_string($result->pullRequestUrl ?? null) ? $result->pullRequestUrl : null,
                commitSha: is_string($result->commitSha ?? null) ? $result->commitSha : null,
            );
        }
    });
    app()->tag(['test.marketplace.deployment-publisher-adapter'], MarketplaceComposerChangePublisher::TAG);
}

it('builds marketplace table records from filtered marketplace listings', function (): void {
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
                    'purchase_url' => 'https://marketplace.test/extensions/seo-audit',
                    'requires_confirmation' => true,
                    'install_confirmation' => [
                        'summary' => 'Installs SEO checks.',
                    ],
                    'install_options' => [
                        ['key' => 'starter_checks', 'type' => 'checkbox', 'label' => 'Starter checks'],
                    ],
                    'product_group' => 'Capell Growth',
                    'catalogue_role' => 'extension',
                    'maturity' => 'stable',
                    'maturity_label' => 'Released',
                    'included_with_capell_all' => true,
                    'author_name' => 'Capell Labs',
                    'author_slug' => 'capell-labs',
                    'ratings_summary' => [
                        'average' => 4.5,
                        'count' => 18,
                    ],
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

    $records = (resolve(MarketplaceCatalogueRecordProvider::class))->records(
        search: 'seo',
        filters: [
            'kind' => ['value' => 'tool'],
            'sort' => ['value' => 'latest'],
            'category' => ['value' => 'seo'],
            'author' => ['author' => 'capell-labs'],
            'capability' => ['values' => ['settings', 'invalid', 'search']],
            'free_only' => ['isActive' => true],
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
            'catalogue_role' => 'extension',
            'maturity' => 'stable',
            'maturity_label' => 'Released',
            'included_with_capell_all' => true,
            'description' => 'Audit public pages.',
            'price_cents' => 1200,
            'price_label' => '$12.00',
            'is_paid' => true,
            'latest_version' => '2.1.0',
            'released_at_label' => 'May 1, 2026',
            'author_name' => 'Capell Labs',
            'author_filter' => 'capell-labs',
            'rating_average' => 4.5,
            'rating_average_label' => '4.5',
            'rating_stars' => ['full', 'full', 'full', 'full', 'half'],
            'ratings_count' => 18,
            'ratings_count_label' => '18 ratings',
            'documentation_url' => 'https://marketplace.test/docs/seo-audit',
            'purchase_url' => 'https://marketplace.test/extensions/seo-audit',
            'marketplace_install_state' => 'blocked',
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

it('renders unrated marketplace table records with a single no ratings label', function (): void {
    $content = view('capell-marketplace::filament.tables.columns.marketplace-extension-pod', [
        'attributes' => new ComponentAttributeBag,
        'getExtraAttributes' => fn (): array => [],
        'getRecord' => fn (): array => [
            'name' => 'Unrated Suite',
            'kind' => 'tool',
            'description' => 'Example unrated extension.',
            'price_label' => '$0.00',
            'is_paid' => false,
            'latest_version' => '1.0.0',
            'released_at_label' => 'May 1, 2026',
            'author_name' => 'Capell Labs',
            'author_filter' => 'capell-labs',
            'rating_average' => null,
            'rating_average_label' => 'No rating',
            'rating_stars' => ['empty', 'empty', 'empty', 'empty', 'empty'],
            'ratings_count' => 0,
            'ratings_count_label' => 'No ratings',
            'category_labels' => [],
            'capability_labels' => [],
            'compatibility_warnings' => [],
        ],
    ])->render();

    expect(preg_match('/>\s*No rating\s*</', $content))->toBe(0)
        ->and(preg_match_all('/>\s*No ratings\s*</', $content))->toBe(1)
        ->and($content)->toContain('role="img"')
        ->and($content)->toContain('★');
});

it('renders marketplace extension images and compacts long tag groups', function (): void {
    $content = view('capell-marketplace::filament.tables.columns.marketplace-extension-pod', [
        'attributes' => new ComponentAttributeBag,
        'getExtraAttributes' => fn (): array => [],
        'getRecord' => fn (): array => [
            'key' => 'workspace-suite',
            'name' => 'Workspace Suite',
            'kind' => 'tool',
            'description' => 'Workspace automation tools.',
            'image_url' => 'https://marketplace.test/images/workspace-suite.png',
            'price_label' => '$0.00',
            'is_paid' => false,
            'latest_version' => '1.0.0',
            'released_at_label' => 'May 1, 2026',
            'author_name' => 'Capell Labs',
            'author_filter' => 'capell-labs',
            'rating_average' => null,
            'rating_average_label' => 'No rating',
            'rating_stars' => ['empty', 'empty', 'empty', 'empty', 'empty'],
            'ratings_count' => 0,
            'ratings_count_label' => 'No ratings',
            'category_labels' => ['Workspaces', 'Automation', 'Operations', 'Publishing'],
            'capability_labels' => ['Settings', 'Search', 'Exports', 'Imports', 'Reports', 'Scheduling'],
            'surface_labels' => ['Admin', 'Frontend', 'CLI', 'Queue', 'API'],
            'compatibility_warnings' => [],
        ],
    ])->render();

    expect($content)
        ->toContain('src="https://marketplace.test/images/workspace-suite.png"')
        ->toContain('alt="Workspace Suite plugin icon"')
        ->toContain('+12 more')
        ->not->toContain('>Publishing<')
        ->not->toContain('>Reports<')
        ->not->toContain('>Scheduling<');
});

it('does not render a blocked tag on marketplace extension cards', function (): void {
    $content = view('capell-marketplace::filament.tables.columns.marketplace-extension-pod', [
        'attributes' => new ComponentAttributeBag,
        'getExtraAttributes' => fn (): array => [],
        'getRecord' => fn (): array => [
            'key' => 'blocked-suite',
            'name' => 'Blocked Suite',
            'composer_name' => 'capell-app/blocked-suite',
            'kind' => 'tool',
            'description' => 'Blocked marketplace extension.',
            'price_label' => '$0.00',
            'is_paid' => false,
            'marketplace_install_state' => 'blocked',
            'latest_version' => '1.0.0',
            'released_at_label' => 'May 1, 2026',
            'author_name' => 'Capell Labs',
            'author_filter' => 'capell-labs',
            'rating_average' => null,
            'rating_average_label' => 'No rating',
            'rating_stars' => ['empty', 'empty', 'empty', 'empty', 'empty'],
            'ratings_count' => 0,
            'ratings_count_label' => 'No ratings',
            'category_labels' => [],
            'capability_labels' => [],
            'surface_labels' => [],
            'compatibility_warnings' => [],
        ],
    ])->render();

    expect($content)->not->toContain('>Blocked<');
});

it('normalizes relative marketplace extension image paths to marketplace urls', function (): void {
    config(['capell-marketplace.marketplace.web_url' => 'https://capell.app']);

    Http::fake([
        'https://marketplace.test/api/extensions*' => Http::response([
            'data' => [
                marketplaceCatalogueExtensionPayload([
                    'slug' => 'image-suite',
                    'name' => 'Image Suite',
                    'composer_name' => 'capell-app/image-suite',
                    'image_url' => 'docs/assets/marketplace/extension-card.jpg',
                    'screenshots' => [
                        ['path' => 'docs/assets/marketplace/screenshot-one.jpg'],
                        ['url' => 'https://cdn.marketplace.test/screenshot-two.jpg'],
                    ],
                ]),
            ],
            'links' => ['next' => null],
        ]),
    ]);

    $records = resolve(MarketplaceCatalogueRecordProvider::class)->records();

    expect($records[0]['image_url'])->toBe('https://capell.app/docs/assets/marketplace/extension-card.jpg');
    expect($records[0]['image_urls'])->toBe([
        'https://capell.app/docs/assets/marketplace/extension-card.jpg',
        'https://capell.app/docs/assets/marketplace/screenshot-one.jpg',
        'https://cdn.marketplace.test/screenshot-two.jpg',
    ]);
});

it('uses next links to keep marketplace pagination visible when total meta is missing', function (): void {
    Http::fake([
        'https://marketplace.test/api/extensions*' => Http::response([
            'data' => [
                marketplaceCatalogueExtensionPayload([
                    'slug' => 'page-one-suite',
                    'name' => 'Page One Suite',
                    'composer_name' => 'capell-app/page-one-suite',
                ]),
            ],
            'links' => ['next' => 'https://marketplace.test/api/extensions?page=2'],
        ]),
    ]);

    $records = resolve(MarketplaceCatalogueRecordProvider::class)->paginatedRecords(
        page: 1,
        perPage: 18,
    );

    expect($records->total())->toBe(19)
        ->and($records->hasMorePages())->toBeTrue();
});

it('caps first page backfill requests when installed marketplace records are hidden', function (): void {
    config(['capell-marketplace.marketplace.max_remote_pages_per_interactive_request' => 1]);

    CapellCore::registerPackage('capell-app/already-installed');
    CapellCore::forcePackageInstalled('capell-app/already-installed');

    Http::fake(function ($request) {
        $page = (int) ($request->data()['page'] ?? 1);

        return Http::response([
            'data' => [
                marketplaceCatalogueExtensionPayload($page === 1
                    ? [
                        'slug' => 'already-installed',
                        'name' => 'Already Installed',
                        'composer_name' => 'capell-app/already-installed',
                    ]
                    : [
                        'slug' => 'cold-page-visible',
                        'name' => 'Cold Page Visible',
                        'composer_name' => 'capell-app/cold-page-visible',
                    ]),
            ],
            'links' => ['next' => $page === 1 ? 'https://marketplace.test/api/extensions?page=2' : null],
            'meta' => [
                'current_page' => $page,
                'per_page' => 18,
                'total' => 2,
            ],
        ]);
    });

    $records = resolve(MarketplaceCatalogueRecordProvider::class)->records();

    expect($records)->toBeEmpty();

    Http::assertSentCount(1);
});

it('marks marketplace browse as unavailable when the catalogue request fails', function (): void {
    Http::fake([
        'https://marketplace.test/api/extensions*' => Http::response(['message' => 'Unavailable'], 503),
    ]);

    $provider = resolve(MarketplaceCatalogueRecordProvider::class);
    $table = resolve(MarketplaceCatalogueTable::class);

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
        ->and($table->marketplaceEmptyStateHeading())->toBe(__('capell-marketplace::marketplace.filters.unavailable_heading'))
        ->and($table->marketplaceEmptyStateDescription())->toBe(
            __('capell-marketplace::marketplace.filters.unavailable_description')
            . ' '
            . __('capell-marketplace::marketplace.filters.unavailable_reason', ['reason' => 'Unavailable']),
        );

    Http::assertSent(fn ($request): bool => str_starts_with((string) $request->url(), 'https://marketplace.test/api/extensions?')
        && $request->data()['search'] === 'seo'
        && ! array_key_exists('kind', $request->data())
        && $request->data()['sort'] === 'latest'
        && ! array_key_exists('installed_status', $request->data())
        && $request->data()['page'] === '1'
        && $request->data()['per_page'] === '18'
        && ! array_key_exists('category', $request->data())
        && ! array_key_exists('capabilities', $request->data())
        && ! array_key_exists('min_price_cents', $request->data())
        && ! array_key_exists('max_price_cents', $request->data()));
});

it('shows marketplace validation details when catalogue browsing fails', function (): void {
    Http::fake([
        'https://marketplace.test/api/extensions*' => Http::response([
            'message' => 'The given data was invalid.',
            'errors' => [
                'instance_id' => ['The instance id field must be a valid UUID.'],
            ],
        ], 422),
    ]);

    $provider = resolve(MarketplaceCatalogueRecordProvider::class);
    $table = resolve(MarketplaceCatalogueTable::class);

    $records = $provider->records();

    expect($records)->toBeEmpty()
        ->and($provider->marketplaceBrowseUnavailable())->toBeTrue()
        ->and($provider->marketplaceBrowseUnavailableReason())->toBe('The instance id field must be a valid UUID.')
        ->and($table->marketplaceEmptyStateDescription())->toContain('The instance id field must be a valid UUID.');
});

it('uses the generic empty state when marketplace browsing is available', function (): void {
    $provider = resolve(MarketplaceCatalogueRecordProvider::class);
    $table = resolve(MarketplaceCatalogueTable::class);

    expect($provider->marketplaceBrowseUnavailable())->toBeFalse()
        ->and($table->marketplaceEmptyStateHeading())->toBe(__('capell-marketplace::marketplace.filters.empty_heading'))
        ->and($table->marketplaceEmptyStateDescription())->toBe(__('capell-marketplace::marketplace.filters.empty_available'));
});

it('does not send an installed status filter to marketplace by default', function (): void {
    CapellCore::registerPackage('capell-app/installed-suite', version: '1.0.0');
    CapellCore::forcePackageInstalled('capell-app/installed-suite');

    Http::fake([
        'https://marketplace.test/api/extensions*' => Http::response([
            'data' => [
                marketplaceCatalogueExtensionPayload([
                    'slug' => 'available-suite',
                    'name' => 'Available Suite',
                    'composer_name' => 'capell-app/available-suite',
                ]),
            ],
            'links' => ['next' => null],
        ]),
    ]);

    $records = resolve(MarketplaceCatalogueRecordProvider::class)->records();

    expect($records)
        ->toHaveCount(1)
        ->and($records[0]['slug'])->toBe('available-suite');

    Http::assertSent(fn ($request): bool => str_starts_with((string) $request->url(), 'https://marketplace.test/api/extensions?')
        && ! array_key_exists('installed_status', $request->data())
        && str_contains((string) $request->data()['installed_composer_names'], 'capell-app/installed-suite'));
});

it('shows downloaded extensions from available marketplace records as non-installable local state', function (): void {
    $packagePath = sys_get_temp_dir() . '/capell-downloaded-suite-' . bin2hex(random_bytes(4));
    mkdir($packagePath, 0777, true);
    file_put_contents($packagePath . '/composer.json', json_encode([
        'name' => 'capell-app/downloaded-suite',
    ], JSON_THROW_ON_ERROR));

    CapellCore::registerPackage('capell-app/downloaded-suite', path: $packagePath);
    CapellCore::forcePackageInstalled('capell-app/downloaded-suite', false);

    Http::fake([
        'https://marketplace.test/api/extensions*' => Http::response([
            'data' => [
                marketplaceCatalogueExtensionPayload([
                    'slug' => 'downloaded-suite',
                    'name' => 'Downloaded Suite',
                    'composer_name' => 'capell-app/downloaded-suite',
                ]),
                marketplaceCatalogueExtensionPayload([
                    'slug' => 'seo-suite',
                    'name' => 'SEO Suite',
                    'composer_name' => 'capell-app/seo-suite',
                ]),
            ],
            'links' => ['next' => null],
        ]),
    ]);

    $records = resolve(MarketplaceCatalogueRecordProvider::class)->records();

    expect($records)
        ->toHaveCount(2)
        ->and($records[0]['slug'])->toBe('downloaded-suite')
        ->and($records[0]['is_installed'])->toBeFalse()
        ->and($records[1]['slug'])->toBe('seo-suite');

    Http::assertSent(fn ($request): bool => str_starts_with((string) $request->url(), 'https://marketplace.test/api/extensions?')
        && ! array_key_exists('installed_status', $request->data())
        && str_contains((string) $request->data()['installed_composer_names'], 'capell-app/downloaded-suite'));
});

it('shows extensions with an active install operation from available marketplace records', function (): void {
    MarketplaceInstallAttempt::query()->create([
        'composer_name' => 'capell-app/installing-suite',
        'extension_slug' => 'installing-suite',
        'extension_name' => 'Installing Suite',
        'kind' => 'tool',
        'status' => MarketplaceInstallIntentStatus::Queued,
        'composer_command' => 'composer require capell-app/installing-suite:^1.0',
        'version_constraint' => '^1.0',
        'queued_at' => now(),
    ]);

    Http::fake([
        'https://marketplace.test/api/extensions*' => Http::response([
            'data' => [[
                'slug' => 'installing-suite',
                'name' => 'Installing Suite',
                'composer_name' => 'capell-app/installing-suite',
                'summary' => 'Already installing',
                'latest_version' => '1.0.0',
            ]],
            'meta' => ['total' => 1, 'per_page' => 18, 'current_page' => 1],
        ]),
    ]);

    $records = (resolve(MarketplaceCatalogueRecordProvider::class))->records();

    expect($records)
        ->toHaveCount(1)
        ->and($records[0]['slug'])->toBe('installing-suite')
        ->and($records[0]['install_in_progress'])->toBeTrue();
});

it('omits the installed status parameter when all marketplace extensions are selected', function (): void {
    CapellCore::registerPackage('capell-app/installed-suite', version: '1.0.0');
    CapellCore::forcePackageInstalled('capell-app/installed-suite');

    Http::fake([
        'https://marketplace.test/api/extensions*' => Http::response([
            'data' => [
                marketplaceCatalogueExtensionPayload([
                    'slug' => 'installed-suite',
                    'name' => 'Installed Suite',
                    'composer_name' => 'capell-app/installed-suite',
                ]),
            ],
            'links' => ['next' => null],
        ]),
    ]);

    resolve(MarketplaceCatalogueRecordProvider::class)->records(filters: [
        'installed_status' => ['value' => ''],
    ]);

    Http::assertSent(fn ($request): bool => str_starts_with((string) $request->url(), 'https://marketplace.test/api/extensions?')
        && ! array_key_exists('installed_status', $request->data())
        && str_contains((string) $request->data()['installed_composer_names'], 'capell-app/installed-suite'));
});

it('uses clear installed status filter labels', function (): void {
    $filterMethod = new ReflectionMethod(MarketplaceCatalogueTable::class, 'getMarketplaceTableFilters');

    $installedStatusFilter = collect($filterMethod->invoke(resolve(MarketplaceCatalogueTable::class)))
        ->first(fn (object $filter): bool => filamentObjectName($filter) === 'installed_status');
    assert(is_object($installedStatusFilter));

    expect($installedStatusFilter)
        ->not->toBeNull()
        ->and(filamentText(filamentObjectMethod($installedStatusFilter, 'getLabel')))->toBe(__('capell-marketplace::marketplace.filters.installed_status'));
});

it('does not expose local extension state without an authenticated admin context', function (): void {
    auth()->logout();
    CapellCore::registerPackage('capell-app/installed-suite', version: '1.0.0');
    CapellCore::forcePackageInstalled('capell-app/installed-suite');

    Http::fake([
        'https://marketplace.test/api/extensions*' => Http::response([
            'data' => [
                marketplaceCatalogueExtensionPayload([
                    'slug' => 'available-suite',
                    'name' => 'Available Suite',
                    'composer_name' => 'capell-app/available-suite',
                ]),
            ],
            'links' => ['next' => null],
        ]),
    ]);

    resolve(MarketplaceCatalogueRecordProvider::class)->records();

    Http::assertSent(fn ($request): bool => str_starts_with((string) $request->url(), 'https://marketplace.test/api/extensions?')
        && ! array_key_exists('installed_status', $request->data())
        && ! array_key_exists('installed_composer_names', $request->data())
        && ! array_key_exists('include_marketplace_context', $request->data()));
});

it('loads browse extensions while hiding package metadata marketplace entries', function (): void {
    Http::fake([
        'https://marketplace.test/api/extensions*' => Http::response([
            'data' => [
                marketplaceCatalogueExtensionPayload([
                    'slug' => 'visible-tool',
                    'name' => 'Visible Tool',
                    'composer_name' => 'capell-app/visible-tool',
                ]),
                marketplaceCatalogueExtensionPayload([
                    'slug' => 'installer',
                    'name' => 'Installer',
                    'composer_name' => 'capell-app/installer',
                ]),
                marketplaceCatalogueExtensionPayload([
                    'slug' => 'marketplace',
                    'name' => 'Marketplace',
                    'composer_name' => 'capell-app/marketplace',
                ]),
                marketplaceCatalogueExtensionPayload([
                    'slug' => 'capell-app-plugins',
                    'name' => 'Capell Marketplace',
                    'composer_name' => 'capell-app/plugins',
                ]),
            ],
            'links' => ['next' => null],
        ]),
    ]);

    $extensions = (resolve(MarketplaceCatalogueRecordProvider::class))->browseExtensions();

    expect($extensions)->toHaveCount(1)
        ->and($extensions[0]->slug)->toBe('visible-tool');

});

it('hides marketplace entries flagged by package metadata', function (): void {
    CapellCore::registerManifestPackage(CapellManifestData::fromArray(capellManifestV3Array(
        name: 'capell-app/setup-helper',
        overrides: [
            'marketplace' => [
                'hidden' => true,
            ],
        ],
    )));

    Http::fake([
        'https://marketplace.test/api/extensions*' => Http::response([
            'data' => [
                marketplaceCatalogueExtensionPayload([
                    'slug' => 'visible-tool',
                    'name' => 'Visible Tool',
                    'composer_name' => 'capell-app/visible-tool',
                ]),
                marketplaceCatalogueExtensionPayload([
                    'slug' => 'setup-helper',
                    'name' => 'Setup Helper',
                    'composer_name' => 'capell-app/setup-helper',
                ]),
            ],
            'links' => ['next' => null],
        ]),
    ]);

    $records = (resolve(MarketplaceCatalogueRecordProvider::class))->records();

    expect($records)->toHaveCount(1)
        ->and($records[0]['slug'])->toBe('visible-tool');
});

it('hides marketplace entries that are still in progress', function (): void {
    Http::fake([
        'https://marketplace.test/api/extensions*' => Http::response([
            'data' => [
                marketplaceCatalogueExtensionPayload([
                    'slug' => 'visible-tool',
                    'name' => 'Visible Tool',
                    'composer_name' => 'capell-app/visible-tool',
                ]),
                marketplaceCatalogueExtensionPayload([
                    'slug' => 'draft-tool',
                    'name' => 'Draft Tool',
                    'composer_name' => 'capell-app/draft-tool',
                    'status' => 'in_progress',
                ]),
            ],
            'links' => ['next' => null],
        ]),
    ]);

    $records = (resolve(MarketplaceCatalogueRecordProvider::class))->records();

    expect($records)->toHaveCount(1)
        ->and($records[0]['slug'])->toBe('visible-tool');
});

it('uses marketplace api pagination for table records', function (): void {
    Http::fake([
        'https://marketplace.test/api/extensions*' => Http::response([
            'data' => [
                marketplaceCatalogueExtensionPayload([
                    'slug' => 'page-two-suite',
                    'name' => 'Page Two Suite',
                    'composer_name' => 'capell-app/page-two-suite',
                ]),
            ],
            'links' => ['next' => 'https://marketplace.test/api/extensions?page=3'],
            'meta' => [
                'current_page' => 2,
                'per_page' => 18,
                'total' => 37,
            ],
        ]),
    ]);

    $records = (resolve(MarketplaceCatalogueRecordProvider::class))->paginatedRecords(
        page: 2,
        perPage: 18,
    );

    expect($records->total())->toBe(37)
        ->and($records->currentPage())->toBe(2)
        ->and($records->perPage())->toBe(18)
        ->and($records->items()[0]['slug'])->toBe('page-two-suite');

    Http::assertSent(fn ($request): bool => str_starts_with((string) $request->url(), 'https://marketplace.test/api/extensions?')
        && $request->data()['page'] === '2'
        && $request->data()['per_page'] === '18');
});

it('clamps marketplace table pagination values before querying the api', function (): void {
    Http::fake([
        'https://marketplace.test/api/extensions*' => Http::response([
            'data' => [],
            'links' => ['next' => null],
            'meta' => [
                'current_page' => 100,
                'per_page' => 18,
                'total' => 0,
            ],
        ]),
    ]);

    (resolve(MarketplaceCatalogueRecordProvider::class))->paginatedRecords(
        page: 999,
        perPage: 1899,
    );

    Http::assertSent(fn ($request): bool => str_starts_with((string) $request->url(), 'https://marketplace.test/api/extensions?')
        && $request->data()['page'] === '100'
        && $request->data()['per_page'] === '18');
});

it('adjusts marketplace totals when package metadata hidden extensions are filtered from a remote page', function (): void {
    Http::fake([
        'https://marketplace.test/api/extensions*' => Http::response([
            'data' => [
                marketplaceCatalogueExtensionPayload([
                    'slug' => 'visible-tool',
                    'name' => 'Visible Tool',
                    'composer_name' => 'capell-app/visible-tool',
                ]),
                marketplaceCatalogueExtensionPayload([
                    'slug' => 'installer',
                    'name' => 'Installer',
                    'composer_name' => 'capell-app/installer',
                ]),
            ],
            'links' => ['next' => null],
            'meta' => [
                'current_page' => 1,
                'per_page' => 18,
                'total' => 2,
            ],
        ]),
    ]);

    $records = (resolve(MarketplaceCatalogueRecordProvider::class))->paginatedRecords();

    expect($records->total())->toBe(1)
        ->and($records->items())->toHaveCount(1)
        ->and($records->items()[0]['slug'])->toBe('visible-tool');
});

it('does not expose local extension inventory or marketplace context when local state is disabled', function (): void {
    CapellCore::registerPackage('capell-app/installed-suite', version: '1.0.0');
    CapellCore::forcePackageInstalled('capell-app/installed-suite');

    MarketplaceInstance::query()->create([
        'instance_id' => 'instance-marketplace-only',
        'signing_secret_encrypted' => 'test-secret',
        'account_id' => 'acct_123',
        'connection_mode' => MarketplaceConnectionMode::AccountLinked,
        'account_email_verified_at' => now(),
        'last_heartbeat_at' => now(),
    ]);

    Http::fake([
        'https://marketplace.test/api/extensions*' => Http::response([
            'data' => [
                marketplaceCatalogueExtensionPayload([
                    'slug' => 'installed-suite',
                    'name' => 'Installed Suite',
                    'composer_name' => 'capell-app/installed-suite',
                    'latest_version' => '2.0.0',
                ]),
            ],
            'links' => ['next' => null],
        ]),
    ]);

    $records = (resolve(MarketplaceCatalogueRecordProvider::class))->records(includeLocalExtensionState: false);

    expect($records[0])->toMatchArray([
        'slug' => 'installed-suite',
        'is_installed' => false,
        'installed_version' => null,
        'has_update_available' => false,
    ]);

    Http::assertSent(fn ($request): bool => str_starts_with((string) $request->url(), 'https://marketplace.test/api/extensions?')
        && ! array_key_exists('installed_status', $request->data())
        && ! array_key_exists('installed_composer_names', $request->data())
        && ! array_key_exists('instance_id', $request->data())
        && ! array_key_exists('account_id', $request->data())
        && ! array_key_exists('domain', $request->data()));
});

it('queues a background refresh when serving stale marketplace table results', function (): void {
    config([
        'capell-marketplace.marketplace.cache_ttl_seconds' => 0,
        'capell-marketplace.marketplace.stale_cache_ttl_seconds' => 300,
    ]);

    Http::fake([
        'https://marketplace.test/api/extensions*' => Http::response([
            'data' => [
                marketplaceCatalogueExtensionPayload([
                    'slug' => 'cached-suite',
                    'name' => 'Cached Suite',
                    'composer_name' => 'capell-app/cached-suite',
                ]),
            ],
            'links' => ['next' => null],
        ]),
    ]);

    $provider = resolve(MarketplaceCatalogueRecordProvider::class);
    $provider->paginatedRecords();
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(2));

    try {
        Bus::fake();

        $records = $provider->paginatedRecords();

        expect($records->items()[0]['slug'])->toBe('cached-suite');

        Bus::assertDispatchedAfterResponse(WarmMarketplaceCatalogueCacheJob::class);
        Http::assertSentCount(1);
    } finally {
        CarbonImmutable::setTestNow();
    }
});

it('throttles duplicate marketplace catalogue warm failure warnings', function (): void {
    Http::fake([
        'https://marketplace.test/api/extensions*' => Http::response(['message' => 'Unavailable'], 503),
    ]);

    Log::shouldReceive('warning')
        ->once()
        ->with(
            'capell-marketplace: marketplace catalogue warm failed',
            Mockery::on(fn (array $context): bool => is_string($context['error'] ?? null) && $context['error'] !== ''),
        );

    WarmMarketplaceCatalogueCacheAction::run();
    WarmMarketplaceCatalogueCacheAction::run();
});

it('marks installed marketplace records with update and compatibility state', function (): void {
    CapellCore::registerPackage('capell-app/seo-suite', version: '2.0.0');
    CapellCore::forcePackageInstalled('capell-app/seo-suite');

    Http::fake([
        'https://marketplace.test/api/extensions*' => Http::response([
            'data' => [
                marketplaceCatalogueExtensionPayload([
                    'slug' => 'seo-suite',
                    'name' => 'SEO Suite',
                    'composer_name' => 'capell-app/seo-suite',
                    'latest_version' => '2.1.0',
                    'is_featured' => true,
                    'featured_rank' => 1,
                    'image_url' => 'https://marketplace.test/images/seo-suite.png',
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

    $installedRecords = (resolve(MarketplaceCatalogueRecordProvider::class))->records(
        filters: [
            'installed_status' => ['value' => 'installed'],
        ],
    );

    expect($installedRecords)->toHaveCount(1);

    $record = $installedRecords[0];

    expect($record)->toMatchArray([
        'slug' => 'seo-suite',
        'composer_name' => 'capell-app/seo-suite',
        'is_featured' => true,
        'featured_rank' => 1,
        'image_url' => 'https://marketplace.test/images/seo-suite.png',
        'is_installed' => true,
        'installed_version' => '2.0.0',
        'has_update_available' => true,
        'is_compatible' => false,
    ])
        ->and($record['category_labels'])->toContain('SEO', 'Bespoke Category')
        ->and($record['capability_labels'])->toContain('Settings', 'Bulk Tools', 'Custom Reports')
        ->and($record['capability_labels'])->not->toContain('Cache')
        ->and($record['compatibility_warnings'])->not->toBeEmpty();

    Http::assertSent(fn ($request): bool => str_starts_with((string) $request->url(), 'https://marketplace.test/api/extensions?')
        && $request->data()['installed_status'] === 'installed'
        && str_contains((string) $request->data()['installed_composer_names'], 'capell-app/seo-suite'));
});

it('trusts server-owned marketplace install state before local paid purchase fallbacks', function (): void {
    Http::fake([
        'https://marketplace.test/api/extensions*' => Http::response([
            'data' => [
                marketplaceCatalogueExtensionPayload([
                    'slug' => 'licensed-suite',
                    'name' => 'Licensed Suite',
                    'composer_name' => 'capell-app/licensed-suite',
                    'price_cents' => 9900,
                    'is_paid' => true,
                    'purchase_url' => 'https://marketplace.test/extensions/licensed-suite',
                    'install_state' => 'activation_required',
                    'install_eligibility' => [
                        'state' => 'activation_required',
                    ],
                    'primary_action' => 'activate',
                    'activation_required' => true,
                ]),
            ],
            'links' => ['next' => null],
        ]),
    ]);

    $records = (resolve(MarketplaceCatalogueRecordProvider::class))->records();

    expect($records)->toHaveCount(1)
        ->and($records[0])->toMatchArray([
            'slug' => 'licensed-suite',
            'marketplace_install_state' => 'blocked',
            'primary_action' => 'activate',
        ])
        ->and(resolve(MarketplaceCatalogueTable::class)->marketplaceInstallState($records[0]))->toBe(
            MarketplaceInstallState::Blocked,
        );
});

it('adds marketplace purchase return context without exposing signing secrets', function (): void {
    app()->instance('request', Request::create('https://cms.test/admin/extensions?tableSearch=seo'));

    MarketplaceInstance::query()->create([
        'instance_id' => 'instance-123',
        'signing_secret_encrypted' => 'test-secret',
        'connection_mode' => MarketplaceConnectionMode::AccountLinked,
        'account_id' => 'acct_123',
        'account_email_verified_at' => now(),
        'last_heartbeat_at' => now(),
    ]);

    $url = resolve(MarketplaceInstallActionPresenter::class)->url([
        'is_installed' => false,
        'is_compatible' => true,
        'is_paid' => true,
        'marketplace_install_state' => 'purchase_required',
        'install_eligibility_policy' => [
            'state' => 'purchase_required',
        ],
        'purchase_url' => 'https://marketplace.test/extensions/seo-suite',
        'composer_name' => 'capell-app/seo-suite',
    ]);

    expect($url)->toContain('source=capell_admin')
        ->and($url)->toContain('instance_id=instance-123')
        ->and($url)->toContain('account_id=acct_123')
        ->and($url)->toContain('composer_name=capell-app%2Fseo-suite')
        ->and($url)->toContain('return_url=https%3A%2F%2Fcms.test%2Fadmin%2Fextensions%3FtableSearch%3Dseo')
        ->and($url)->not->toContain('test-secret');
});

it('drops untrusted remote marketplace urls before rendering records or purchase actions', function (): void {
    Http::fake([
        'https://marketplace.test/api/extensions*' => Http::response([
            'data' => [
                marketplaceCatalogueExtensionPayload([
                    'slug' => 'redirect-suite',
                    'name' => 'Redirect Suite',
                    'composer_name' => 'capell-app/redirect-suite',
                    'price_cents' => 9900,
                    'is_paid' => true,
                    'documentation_url' => 'https://example.test/docs',
                    'purchase_url' => 'javascript:alert(1)',
                ]),
            ],
            'links' => ['next' => null],
        ]),
    ]);

    $records = (resolve(MarketplaceCatalogueRecordProvider::class))->records();

    expect($records[0]['documentation_url'])->toBeNull()
        ->and($records[0]['purchase_url'])->toBeNull();

    expect(resolve(MarketplaceInstallActionPresenter::class)->url([
        'is_installed' => false,
        'is_compatible' => true,
        'is_paid' => true,
        'marketplace_install_state' => 'purchase_required',
        'purchase_url' => 'https://example.test/extensions/redirect-suite',
        'composer_name' => 'capell-app/redirect-suite',
    ]))->toBeNull()
        ->and(resolve(MarketplaceInstallActionPresenter::class)->url([
            'is_installed' => false,
            'is_compatible' => true,
            'is_paid' => true,
            'marketplace_install_state' => 'purchase_required',
            'purchase_url' => 'http://marketplace.test/extensions/redirect-suite',
            'composer_name' => 'capell-app/redirect-suite',
        ]))->toBeNull();
});

it('allows premium purchase links before marketplace account connection', function (): void {
    app()->instance('request', Request::create('https://cms.test/admin/extensions'));

    $presenter = resolve(MarketplaceInstallActionPresenter::class);
    $record = [
        'is_installed' => false,
        'is_compatible' => true,
        'is_paid' => true,
        'marketplace_install_state' => 'purchase_required',
        'install_eligibility_policy' => [
            'state' => 'purchase_required',
        ],
        'purchase_url' => 'https://marketplace.test/extensions/seo-suite',
        'composer_name' => 'capell-app/seo-suite',
    ];

    expect($presenter->url($record))->toContain('https://marketplace.test/extensions/seo-suite?source=capell_admin')
        ->and($presenter->url($record))->toContain('composer_name=capell-app%2Fseo-suite')
        ->and($presenter->blockReason($record))->toBeNull();
});

it('blocks install actions while a marketplace install operation is active', function (): void {
    $presenter = resolve(MarketplaceInstallActionPresenter::class);
    $record = [
        'is_installed' => false,
        'is_compatible' => true,
        'is_paid' => false,
        'install_in_progress' => true,
        'marketplace_install_state' => 'free_available',
        'composer_name' => 'capell-app/seo-suite',
    ];

    expect($presenter->state($record))->toBe(MarketplaceInstallState::Blocked)
        ->and($presenter->blockReason($record))->toBe('install_in_progress')
        ->and($presenter->label($record))->toBe(__('capell-marketplace::marketplace.install.blocked.install_in_progress.title'))
        ->and($presenter->tooltip($record))->toBe(__('capell-marketplace::marketplace.install.blocked.install_in_progress.tooltip'));
});

it('blocks duplicate queueing while deployment publishing is still running', function (): void {
    ensureDeploymentPublisherTestContracts();

    $listing = marketplaceQueueListing();
    $acquisition = marketplaceQueueAcquisition();
    $eligibility = new MarketplaceInstallEligibilityData(
        state: MarketplaceInstallState::Authorized,
        canInstall: true,
    );
    $policyEvidence = BuildMarketplaceInstallPolicyEvidenceAction::run($listing);
    $duplicateBlockState = (object) ['wasBlocked' => false];

    app()->instance('Capell\\Deployments\\Contracts\\PublishesComposerChanges', new readonly class($listing, $acquisition, $eligibility, $policyEvidence, $duplicateBlockState)
    {
        public function __construct(
            private ExtensionListingData $listing,
            private ExtensionAcquisitionData $acquisition,
            private MarketplaceInstallEligibilityData $eligibility,
            private MarketplaceInstallPolicyEvidenceData $policyEvidence,
            private stdClass $duplicateBlockState,
        ) {}

        public function publish(object $requirement): stdClass
        {
            try {
                QueueMarketplaceInstallAttemptAction::run(
                    listing: $this->listing,
                    acquisition: $this->acquisition,
                    eligibility: $this->eligibility,
                    betaAcknowledged: false,
                    policyEvidence: $this->policyEvidence,
                    actor: MarketplaceInstallActorData::system('marketplace-test'),
                    source: MarketplaceInstallSource::Programmatic,
                );
            } catch (ValidationException) {
                $this->duplicateBlockState->wasBlocked = true;
            }

            return (object) [
                'pullRequestUrl' => null,
                'commitSha' => 'abc123',
            ];
        }
    });

    $attempt = QueueMarketplaceInstallAttemptAction::run(
        listing: $listing,
        acquisition: $acquisition,
        eligibility: $eligibility,
        betaAcknowledged: false,
        policyEvidence: $policyEvidence,
        actor: MarketplaceInstallActorData::system('marketplace-test'),
        source: MarketplaceInstallSource::Programmatic,
    );

    expect($duplicateBlockState->wasBlocked)->toBeTrue()
        ->and(MarketplaceInstallAttempt::query()->count())->toBe(1)
        ->and($attempt->refresh()->deployment)->toMatchArray([
            'status' => 'published',
            'reference' => 'abc123',
        ]);
});

it('blocks purchase actions when checkout urls are unavailable after account connection', function (): void {
    MarketplaceInstance::query()->create([
        'instance_id' => 'instance-123',
        'signing_secret_encrypted' => 'test-secret',
        'connection_mode' => MarketplaceConnectionMode::AccountLinked,
        'account_id' => 'acct_123',
        'account_email_verified_at' => now(),
        'last_heartbeat_at' => now(),
    ]);

    app()->instance('request', Request::create('https://cms.test/admin/extensions'));

    expect(resolve(MarketplaceInstallActionPresenter::class)->blockReason([
        'is_installed' => false,
        'is_compatible' => true,
        'is_paid' => true,
        'marketplace_install_state' => 'purchase_required',
        'install_eligibility_policy' => [
            'state' => 'purchase_required',
        ],
        'purchase_url' => null,
        'composer_name' => 'capell-app/paid-suite',
    ]))->toBe('checkout_unavailable');
});

it('keeps table purchase actions blocked when checkout urls are unavailable', function (): void {
    MarketplaceInstance::query()->create([
        'instance_id' => 'instance-123',
        'signing_secret_encrypted' => 'test-secret',
        'connection_mode' => MarketplaceConnectionMode::AccountLinked,
        'account_id' => 'acct_123',
        'account_email_verified_at' => now(),
        'last_heartbeat_at' => now(),
    ]);

    app()->instance('request', Request::create('https://cms.test/admin/extensions'));

    $record = [
        'is_installed' => false,
        'is_compatible' => true,
        'is_paid' => true,
        'marketplace_install_state' => 'purchase_required',
        'install_eligibility_policy' => [
            'state' => 'purchase_required',
        ],
        'purchase_url' => null,
        'composer_name' => 'capell-app/paid-suite',
    ];
    $presenter = resolve(MarketplaceInstallActionPresenter::class);

    expect($presenter->url($record))->toBeNull()
        ->and($presenter->blockReason($record))->toBe('checkout_unavailable');
});

it('keeps incompatible marketplace actions reachable for an explanatory notification', function (): void {
    expect(resolve(MarketplaceInstallActionPresenter::class)->blockReason([
        'is_installed' => false,
        'is_compatible' => false,
        'is_paid' => false,
    ]))->toBe('incompatible');
});

it('derives legacy marketplace install button states when policy payloads are absent', function (): void {
    $presenter = resolve(MarketplaceInstallActionPresenter::class);

    expect($presenter->state([
        'is_installed' => false,
        'is_compatible' => true,
        'is_paid' => false,
        'install_state' => 'authorized',
    ]))->toBe(MarketplaceInstallState::Authorized)
        ->and($presenter->label([
            'is_paid' => false,
            'install_state' => 'authorized',
        ]))->toBe((string) __('capell-marketplace::marketplace.install.button'))
        ->and($presenter->color([
            'is_paid' => false,
            'install_state' => 'authorized',
        ]))->toBe('primary')
        ->and($presenter->tooltip([
            'is_paid' => false,
            'install_state' => 'authorized',
        ]))->toBe((string) __('capell-marketplace::marketplace.install.tooltip'))
        ->and($presenter->url([
            'is_paid' => false,
            'install_state' => 'authorized',
            'purchase_url' => 'https://marketplace.test/extensions/legacy-suite',
        ]))->toBeNull()
        ->and($presenter->state([
            'is_paid' => false,
            'install_authorized' => true,
        ]))->toBe(MarketplaceInstallState::Authorized)
        ->and($presenter->state([
            'is_paid' => false,
            'activation_required' => true,
            'install_eligibility_policy' => [],
        ]))->toBe(MarketplaceInstallState::ActivationRequired)
        ->and($presenter->label([
            'is_paid' => false,
            'activation_required' => true,
            'install_eligibility_policy' => [],
        ]))->toBe((string) __('capell-marketplace::marketplace.install.activate_button'))
        ->and($presenter->color([
            'is_paid' => false,
            'activation_required' => true,
            'install_eligibility_policy' => [],
        ]))->toBe('warning')
        ->and($presenter->state([
            'is_paid' => true,
            'purchase_url' => 'https://marketplace.test/extensions/legacy-suite',
            'install_eligibility_policy' => [],
        ]))->toBe(MarketplaceInstallState::PurchaseRequired)
        ->and($presenter->state([
            'is_paid' => true,
            'install_eligibility_policy' => [],
        ]))->toBe(MarketplaceInstallState::PurchaseRequired)
        ->and($presenter->state([
            'is_paid' => false,
        ]))->toBe(MarketplaceInstallState::FreeAvailable);
});

it('keeps purchase activation free and installed presentation independent of release metadata', function (): void {
    $presenter = resolve(MarketplaceInstallActionPresenter::class);
    $releaseMetadata = [
        'catalogue_role' => 'extension',
        'maturity' => 'stable',
        'maturity_label' => 'Released',
        'included_with_capell_all' => true,
    ];

    expect($presenter->state([
        ...$releaseMetadata,
        'is_paid' => false,
    ]))->toBe(MarketplaceInstallState::FreeAvailable)
        ->and($presenter->label([
            ...$releaseMetadata,
            'is_paid' => false,
        ]))->toBe((string) __('capell-marketplace::marketplace.install.button'))
        ->and($presenter->state([
            ...$releaseMetadata,
            'is_paid' => true,
            'purchase_url' => 'https://marketplace.test/extensions/release-suite',
            'install_eligibility_policy' => [],
        ]))->toBe(MarketplaceInstallState::PurchaseRequired)
        ->and($presenter->state([
            ...$releaseMetadata,
            'is_paid' => false,
            'activation_required' => true,
            'install_eligibility_policy' => [],
        ]))->toBe(MarketplaceInstallState::ActivationRequired)
        ->and($presenter->state([
            ...$releaseMetadata,
            'is_installed' => true,
        ]))->toBe(MarketplaceInstallState::Installed);
});

it('fails closed when installed extension catalogue metadata is unavailable', function (int $responseStatus): void {
    Http::fake([
        'https://marketplace.test/api/extensions/by-composer*' => Http::response(
            $responseStatus === 404 ? [] : ['message' => 'Unavailable'],
            $responseStatus,
        ),
    ]);

    $records = EnrichExtensionTableRecordsAction::run([
        [
            'packageName' => 'capell-app/release-suite',
            'label' => 'Release Suite',
        ],
    ]);

    expect($records)->toHaveCount(1)
        ->and($records[0])->toMatchArray([
            'catalogueRole' => 'extension',
            'maturity' => 'labs',
            'maturityLabel' => 'Labs',
            'includedWithCapellAll' => false,
        ]);
})->with([
    'missing exact lookup endpoint' => [404],
    'marketplace unavailable' => [503],
]);

it('maps canonical catalogue metadata back to legacy installed package names', function (): void {
    Http::fake([
        'https://marketplace.test/api/extensions/by-composer*' => Http::response([
            'data' => [[
                'slug' => 'layout-builder',
                'name' => 'Layout Builder',
                'composer_name' => 'capell-app/layout-builder',
                'catalogue_role' => 'core',
                'maturity' => 'stable',
                'maturity_label' => 'Released',
                'included_with_capell_all' => true,
            ]],
        ]),
    ]);

    $records = EnrichExtensionTableRecordsAction::run([
        [
            'packageName' => 'capell-app/mosaic',
            'label' => 'Layout Builder',
        ],
    ]);

    expect($records[0])->toMatchArray([
        'catalogueRole' => 'core',
        'maturity' => 'stable',
        'maturityLabel' => 'Released',
        'includedWithCapellAll' => true,
    ]);
});

it('notifies admins when marketplace account state blocks install controls', function (): void {
    $record = [
        'is_installed' => false,
        'is_compatible' => true,
        'is_paid' => true,
        'install_eligibility_policy' => [
            'state' => 'blocked',
            'block_reason' => 'account_required',
            'can_install' => false,
        ],
    ];

    $presenter = resolve(MarketplaceInstallActionPresenter::class);

    expect($presenter->sendBlockedNotification([
        'is_paid' => false,
    ]))->toBeFalse()
        ->and($presenter->sendBlockedNotification($record))->toBeTrue();

    Notification::assertNotified((string) __('capell-marketplace::marketplace.install.blocked.account_required.title'));
});

it('can build marketplace records for a locked theme browser', function (): void {
    Http::fake([
        'https://marketplace.test/api/extensions*' => Http::response([
            'data' => [
                marketplaceCatalogueExtensionPayload([
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
        filters: [],
        lockedKind: 'theme',
    );

    expect($records)->toHaveCount(1)
        ->and($records[0]['kind'])->toBe('theme');

    Http::assertSent(fn ($request): bool => str_starts_with((string) $request->url(), 'https://marketplace.test/api/extensions?')
        && $request->data()['kind'] === 'theme'
        && ! array_key_exists('installed_status', $request->data()));
});

it('locks marketplace catalogue records and filters to the configured extension kind', function (): void {
    Http::fake([
        'https://marketplace.test/api/extensions*' => Http::response([
            'data' => [
                marketplaceCatalogueExtensionPayload([
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
        ->and($records[0]['kind'])->toBe('theme')
        ->and($filterNames)->not->toContain('kind');

    Http::assertSent(fn ($request): bool => str_starts_with((string) $request->url(), 'https://marketplace.test/api/extensions?')
        && $request->data()['kind'] === 'theme');
});

it('handles missing marketplace listings without requesting authorization', function (): void {
    Http::fake([
        'https://marketplace.test/api/extensions/missing-suite' => Http::response([], 404),
    ]);

    (resolve(MarketplaceCatalogueTable::class))->installExtension(['slug' => 'missing-suite']);

    Http::assertSentCount(1);
});

it('installs a free marketplace extension using selected options and queued telemetry', function (): void {
    config([
        'app.url' => 'https://example.test',
        'capell-marketplace.instance.id' => 'instance-123',
        'capell-marketplace.marketplace.webhook_secret' => 'test-secret',
    ]);

    Http::fake([
        'https://marketplace.test/api/extensions/seo-suite' => Http::response([
            'data' => marketplaceCatalogueExtensionPayload([
                'slug' => 'seo-suite',
                'name' => 'SEO Suite',
                'composer_name' => 'capell-app/seo-suite',
                'latest_version' => '2.1.0',
                'install_options' => [
                    ['key' => 'starter_content', 'type' => 'checkbox', 'label' => 'Starter content'],
                    ['key' => 'mode', 'type' => 'radio', 'label' => 'Mode'],
                ],
            ]),
        ]),
        'https://marketplace.test/api/extensions/install-intents' => Http::response(['data' => ['recorded' => true]]),
    ]);

    (resolve(MarketplaceCatalogueTable::class))->installExtension(
        ['slug' => 'seo-suite'],
        [
            'email' => 'owner@example.test',
            'license_key' => 'license-123',
            'install_options' => [
                'starter_content' => true,
                'ignored' => true,
            ],
        ],
    );

    Http::assertNotSent(fn ($request): bool => $request->url() === 'https://marketplace.test/api/extensions/seo-suite/install-authorization');

    Http::assertSent(fn ($request): bool => $request->url() === 'https://marketplace.test/api/extensions/install-intents'
        && $request->data()['slug'] === 'seo-suite'
        && $request->data()['install_options'] === ['starter_content' => true]);

    $attempt = MarketplaceInstallAttempt::query()->sole();

    expect($attempt->requested_options)->toBe(['starter_content' => true])
        ->and($attempt->telemetry_status)->toBe('synced');
});

it('records free install attempts as pending before queued telemetry syncs', function (): void {
    Queue::fake();

    Http::fake([
        'https://marketplace.test/api/extensions/seo-suite' => Http::response([
            'data' => marketplaceCatalogueExtensionPayload([
                'slug' => 'seo-suite',
                'name' => 'SEO Suite',
                'composer_name' => 'capell-app/seo-suite',
                'latest_version' => '2.1.0',
            ]),
        ]),
    ]);

    (resolve(MarketplaceCatalogueTable::class))->installExtension(['slug' => 'seo-suite']);

    $attempt = MarketplaceInstallAttempt::query()->sole();

    expect($attempt->telemetry_status)->toBe('pending');

    Queue::assertPushed(SendMarketplaceInstallTelemetryJob::class);
    Http::assertNotSent(fn ($request): bool => $request->url() === 'https://marketplace.test/api/extensions/seo-suite/install-authorization');
});

it('adds the theme activation next step to theme install guidance', function (): void {
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
        'https://marketplace.test/api/extensions/agency-theme' => Http::response([
            'data' => marketplaceCatalogueExtensionPayload([
                'slug' => 'agency-theme',
                'name' => 'Agency Theme',
                'composer_name' => 'capell-app/theme-agency',
                'kind' => 'theme',
                'latest_version' => '1.2.0',
            ]),
        ]),
        'https://marketplace.test/api/extensions/agency-theme/install-authorization' => Http::response([
            'data' => [
                'composer_name' => 'capell-app/theme-agency',
                'version_constraint' => '^1.2',
            ],
        ]),
        'https://marketplace.test/api/extensions/install-intents' => Http::response(['data' => ['recorded' => true]]),
    ]);

    (resolve(MarketplaceCatalogueTable::class))->installExtension(['slug' => 'agency-theme']);
    $attempt = MarketplaceInstallAttempt::query()->where('composer_name', 'capell-app/theme-agency')->sole();

    Http::assertNotSent(fn ($request): bool => $request->url() === 'https://marketplace.test/api/extensions/agency-theme/install-authorization');

    Notification::assertNotified(
        Notification::make(MarketplaceInstallNotifications::operationId('capell-app/theme-agency'))
            ->title((string) __('capell-marketplace::marketplace.install.local_queued'))
            ->body(__('capell-marketplace::marketplace.install.local_queued_body', [
                'name' => 'Agency Theme',
            ]) . PHP_EOL . PHP_EOL . __('capell-marketplace::marketplace.themes.installed_next_step'))
            ->success()
            ->persistent()
            ->actions([
                Action::make('viewMarketplaceInstallOperation')
                    ->label((string) __('capell-marketplace::marketplace.install.check_operation'))
                    ->icon(Heroicon::OutlinedQueueList)
                    ->link()
                    ->close()
                    ->url(MarketplacePackageOperationsPage::getUrl([
                        'tab' => 'active',
                        'operation' => $attempt->getKey(),
                    ])),
            ]),
    );
});

it('records a pending theme install intent and append-only attempt after theme install guidance is generated', function (): void {
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
        'https://marketplace.test/api/extensions/agency-theme' => Http::response([
            'data' => marketplaceCatalogueExtensionPayload([
                'slug' => 'agency-theme',
                'name' => 'Agency Theme',
                'composer_name' => 'capell-app/theme-agency',
                'kind' => 'theme',
                'description' => 'A polished agency theme.',
                'image_url' => 'https://marketplace.test/images/theme-agency.png',
                'latest_version' => '1.2.0',
            ]),
        ]),
        'https://marketplace.test/api/extensions/agency-theme/install-authorization' => Http::response([
            'data' => [
                'composer_name' => 'capell-app/theme-agency',
                'version_constraint' => '^1.2',
            ],
        ]),
        'https://marketplace.test/api/extensions/install-intents' => Http::response(['data' => ['recorded' => true]]),
    ]);

    (resolve(MarketplaceCatalogueTable::class))->installExtension(['slug' => 'agency-theme']);

    Http::assertNotSent(fn ($request): bool => $request->url() === 'https://marketplace.test/api/extensions/agency-theme/install-authorization');

    $intent = MarketplaceInstallIntent::query()->sole();
    $attempt = MarketplaceInstallAttempt::query()->sole();

    expect($intent->extension_slug)->toBe('agency-theme')
        ->and($intent->extension_name)->toBe('Agency Theme')
        ->and($intent->composer_name)->toBe('capell-app/theme-agency')
        ->and($intent->kind)->toBe('theme')
        ->and($intent->status)->toBe(MarketplaceInstallIntentStatus::Pending)
        ->and($intent->composer_command)->toBe('composer require capell-app/theme-agency:^1.2.0')
        ->and($intent->version_constraint)->toBe('^1.2.0')
        ->and($intent->metadata)->toMatchArray([
            'image_url' => 'https://marketplace.test/images/theme-agency.png',
            'description' => 'A polished agency theme.',
        ])
        ->and($attempt->extension_slug)->toBe('agency-theme')
        ->and($attempt->kind)->toBe('theme')
        ->and($attempt->status)->toBe(MarketplaceInstallIntentStatus::Queued)
        ->and($attempt->deployment)->toMatchArray([
            'status' => 'unavailable',
            'fallback' => 'composer_command',
            'image_url' => 'https://marketplace.test/images/theme-agency.png',
            'description' => 'A polished agency theme.',
        ]);
});

it('records install attempts for non-theme marketplace installs', function (): void {
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
        'https://marketplace.test/api/extensions/seo-suite' => Http::response([
            'data' => marketplaceCatalogueExtensionPayload([
                'slug' => 'seo-suite',
                'name' => 'SEO Suite',
                'composer_name' => 'capell-app/seo-suite',
                'kind' => 'tool',
                'latest_version' => '2.1.0',
            ]),
        ]),
        'https://marketplace.test/api/extensions/seo-suite/install-authorization' => Http::response([
            'data' => [
                'composer_name' => 'capell-app/seo-suite',
                'version_constraint' => '^2.1',
                'signed_activation' => [
                    'activation_id' => 'act_123',
                    'expires_at' => '2026-05-08T10:00:00+00:00',
                    'signature' => 'signed-secret',
                ],
                'metadata' => [
                    'secret' => 'sensitive',
                    'policy' => 'manual',
                ],
            ],
        ]),
        'https://marketplace.test/api/extensions/install-intents' => Http::response(['data' => ['recorded' => true]]),
    ]);

    (resolve(MarketplaceCatalogueTable::class))->installExtension(['slug' => 'seo-suite']);

    $intent = MarketplaceInstallAttempt::query()->sole();

    expect($intent->extension_slug)->toBe('seo-suite')
        ->and($intent->kind)->toBe('tool')
        ->and($intent->status)->toBe(MarketplaceInstallIntentStatus::Queued)
        ->and($intent->deployment)->toMatchArray([
            'authorization' => [
                'signed_activation_present' => false,
                'metadata_keys' => ['authorization_source'],
            ],
        ])
        ->and(json_encode($intent->deployment, JSON_THROW_ON_ERROR))->not->toContain('signed-secret')
        ->and(json_encode($intent->deployment, JSON_THROW_ON_ERROR))->not->toContain('sensitive');
});

it('records deployment published attempts when deployment publisher returns a pull request', function (): void {
    ensureDeploymentPublisherTestContracts();

    config([
        'app.url' => 'https://example.test',
        'capell-marketplace.instance.id' => 'instance-123',
        'capell-marketplace.marketplace.webhook_secret' => 'test-secret',
    ]);

    app()->instance('Capell\\Deployments\\Contracts\\PublishesComposerChanges', new class
    {
        public function publish(object $requirement): stdClass
        {
            return (object) [
                'pullRequestUrl' => 'https://github.test/capell/pulls/42',
                'commitSha' => null,
            ];
        }
    });

    Http::fake([
        'https://marketplace.test/api/extensions/seo-suite' => Http::response([
            'data' => marketplaceCatalogueExtensionPayload([
                'slug' => 'seo-suite',
                'name' => 'SEO Suite',
                'composer_name' => 'capell-app/seo-suite',
                'kind' => 'tool',
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

    (resolve(MarketplaceCatalogueTable::class))->installExtension(['slug' => 'seo-suite']);

    $attempt = MarketplaceInstallAttempt::query()->sole();

    expect($attempt->status)->toBe(MarketplaceInstallIntentStatus::Queued)
        ->and($attempt->composer_command)->toBe('composer require capell-app/seo-suite:^1.0.0')
        ->and($attempt->queued_at)->not->toBeNull()
        ->and($attempt->deployment)->toMatchArray([
            'status' => 'published',
            'type' => 'pull_request',
            'reference' => 'https://github.test/capell/pulls/42',
        ]);

    Notification::assertNotified((string) __('capell-marketplace::marketplace.install.composer_sync_ready'));
});

it('can install filament peek through the admin marketplace catalogue', function (): void {
    config([
        'app.url' => 'https://example.test',
        'capell-marketplace.instance.id' => 'instance-123',
        'capell-marketplace.marketplace.webhook_secret' => 'test-secret',
    ]);

    Http::fake([
        'https://marketplace.test/api/extensions/filament-peek' => Http::response([
            'data' => marketplaceCatalogueExtensionPayload([
                'slug' => 'filament-peek',
                'name' => 'Filament Peek',
                'composer_name' => 'capell-app/filament-peek',
                'kind' => 'tool',
                'description' => 'Private previews for unsaved Capell editor changes.',
                'latest_version' => '4.1.2',
            ]),
        ]),
        'https://marketplace.test/api/extensions/install-intents' => Http::response(['data' => ['recorded' => true]]),
    ]);

    (resolve(MarketplaceCatalogueTable::class))->installExtension(['slug' => 'filament-peek']);

    $attempt = MarketplaceInstallAttempt::query()->sole();

    expect($attempt->status)->toBe(MarketplaceInstallIntentStatus::Queued)
        ->and($attempt->extension_slug)->toBe('filament-peek')
        ->and($attempt->composer_name)->toBe('capell-app/filament-peek')
        ->and($attempt->composer_command)->toBe('composer require capell-app/filament-peek:^4.1.2')
        ->and($attempt->version_constraint)->toBe('^4.1.2')
        ->and($attempt->requested_options)->toBeNull()
        ->and($attempt->eligibility)->toMatchArray([
            'state' => MarketplaceInstallState::FreeAvailable->value,
            'canInstall' => true,
        ])
        ->and($attempt->deployment)->toMatchArray([
            'status' => 'unavailable',
            'fallback' => 'composer_command',
        ]);

    Http::assertSent(fn ($request): bool => $request->url() === 'https://marketplace.test/api/extensions/filament-peek');
    Http::assertSent(fn ($request): bool => $request->url() === 'https://marketplace.test/api/extensions/install-intents');
});

it('records deployment published attempts when deployment publisher returns a commit sha', function (): void {
    ensureDeploymentPublisherTestContracts();

    config([
        'app.url' => 'https://example.test',
        'capell-marketplace.instance.id' => 'instance-123',
        'capell-marketplace.marketplace.webhook_secret' => 'test-secret',
    ]);

    app()->instance('Capell\\Deployments\\Contracts\\PublishesComposerChanges', new class
    {
        public function publish(object $requirement): stdClass
        {
            return (object) [
                'pullRequestUrl' => null,
                'commitSha' => 'abc123',
            ];
        }
    });

    Http::fake([
        'https://marketplace.test/api/extensions/seo-suite' => Http::response([
            'data' => marketplaceCatalogueExtensionPayload([
                'slug' => 'seo-suite',
                'name' => 'SEO Suite',
                'composer_name' => 'capell-app/seo-suite',
                'kind' => 'tool',
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

    (resolve(MarketplaceCatalogueTable::class))->installExtension(['slug' => 'seo-suite']);

    $attempt = MarketplaceInstallAttempt::query()->sole();

    expect($attempt->status)->toBe(MarketplaceInstallIntentStatus::Queued)
        ->and($attempt->composer_command)->toBe('composer require capell-app/seo-suite:^1.0.0')
        ->and($attempt->deployment)->toMatchArray([
            'status' => 'published',
            'type' => 'commit',
            'reference' => 'abc123',
        ]);
});

it('falls back to composer command when no active deployment connection exists', function (): void {
    ensureDeploymentPublisherTestContracts();

    config([
        'app.url' => 'https://example.test',
        'capell-marketplace.instance.id' => 'instance-123',
        'capell-marketplace.marketplace.webhook_secret' => 'test-secret',
    ]);

    app()->instance('Capell\\Deployments\\Contracts\\PublishesComposerChanges', new class
    {
        public function publish(object $requirement): object
        {
            throw (new ModelNotFoundException)->setModel('Capell\\Deployments\\Models\\DeploymentConnection');
        }
    });

    Http::fake([
        'https://marketplace.test/api/extensions/seo-suite' => Http::response([
            'data' => marketplaceCatalogueExtensionPayload([
                'slug' => 'seo-suite',
                'name' => 'SEO Suite',
                'composer_name' => 'capell-app/seo-suite',
                'kind' => 'tool',
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

    (resolve(MarketplaceCatalogueTable::class))->installExtension(['slug' => 'seo-suite']);

    $attempt = MarketplaceInstallAttempt::query()->sole();

    expect($attempt->status)->toBe(MarketplaceInstallIntentStatus::Queued)
        ->and($attempt->composer_command)->toBe('composer require capell-app/seo-suite:^1.0.0')
        ->and($attempt->failure_reason)->toBeNull()
        ->and($attempt->deployment)->toMatchArray([
            'status' => 'unavailable',
            'fallback' => 'composer_command',
        ]);

    expect(filamentNotificationTitles())
        ->toContain((string) __('capell-marketplace::marketplace.install.local_queued'))
        ->not->toContain((string) __('capell-marketplace::marketplace.install.composer_sync_failed'));
});

it('records deployment failures separately before showing composer fallback', function (): void {
    ensureDeploymentPublisherTestContracts();

    $redactedReason = 'Deployment connection password=[redacted] Bearer [redacted] is disconnected.';

    config([
        'app.url' => 'https://example.test',
        'capell-marketplace.instance.id' => 'instance-123',
        'capell-marketplace.marketplace.webhook_secret' => 'test-secret',
    ]);

    app()->instance('Capell\\Deployments\\Contracts\\PublishesComposerChanges', new class
    {
        public function publish(object $requirement): object
        {
            throw new RuntimeException('Deployment connection password=hunter2 Bearer ghp_secret_token is disconnected.');
        }
    });

    Http::fake([
        'https://marketplace.test/api/extensions/seo-suite' => Http::response([
            'data' => marketplaceCatalogueExtensionPayload([
                'slug' => 'seo-suite',
                'name' => 'SEO Suite',
                'composer_name' => 'capell-app/seo-suite',
                'kind' => 'tool',
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

    (resolve(MarketplaceCatalogueTable::class))->installExtension(['slug' => 'seo-suite']);

    $attempt = MarketplaceInstallAttempt::query()->sole();

    expect($attempt->status)->toBe(MarketplaceInstallIntentStatus::Queued)
        ->and($attempt->composer_command)->toBe('composer require capell-app/seo-suite:^1.0.0')
        ->and($attempt->failure_reason)->toBe((string) __('capell-marketplace::marketplace.operations.deployment_failed_notification', [
            'reason' => $redactedReason,
        ]))
        ->and($attempt->failure_reason)->not->toContain('hunter2')
        ->and($attempt->failure_reason)->not->toContain('ghp_secret_token')
        ->and($attempt->deployment)->toMatchArray([
            'status' => 'failed',
            'fallback' => 'composer_command',
            'failure_reason' => $redactedReason,
        ]);

    expect(filamentNotificationTitles())->toContain(
        (string) __('capell-marketplace::marketplace.install.local_queued'),
        (string) __('capell-marketplace::marketplace.install.composer_sync_failed'),
    );
});

it('guides admins to connect marketplace when install authorization needs a registered instance', function (): void {
    config([
        'capell-marketplace.instance.id' => null,
        'capell-marketplace.marketplace.webhook_secret' => null,
    ]);

    Http::fake([
        'https://marketplace.test/api/extensions/private-suite' => Http::response([
            'data' => marketplaceCatalogueExtensionPayload([
                'slug' => 'private-suite',
                'name' => 'Private Suite',
                'composer_name' => 'capell-app/private-suite',
            ]),
        ]),
    ]);

    (resolve(MarketplaceCatalogueTable::class))->installExtension(['slug' => 'private-suite']);

    Http::assertSent(fn ($request): bool => $request->url() === 'https://marketplace.test/api/extensions/private-suite');
});

it('redirects account-required installs to Capell App login when connection startup fails', function (): void {
    config([
        'app.url' => 'https://example.test',
        'capell-marketplace.instance.id' => null,
        'capell-marketplace.marketplace.web_url' => 'https://capell.test',
        'capell-marketplace.marketplace.webhook_secret' => null,
    ]);

    Http::fake([
        'https://marketplace.test/api/extensions/private-suite' => Http::response([
            'data' => marketplaceCatalogueExtensionPayload([
                'slug' => 'private-suite',
                'name' => 'Private Suite',
                'composer_name' => 'capell-app/private-suite',
                'price_cents' => 9900,
                'is_paid' => true,
                'install_eligibility' => [
                    'state' => 'account_required',
                    'block_reason' => 'account_required',
                ],
            ]),
        ]),
        'https://marketplace.test/api/marketplace/connections' => Http::response([
            'message' => 'Marketplace unavailable.',
        ], 503),
    ]);

    $redirectUrl = (resolve(MarketplaceCatalogueTable::class))->installExtension(
        ['slug' => 'private-suite'],
        redirectAccountActions: true,
    );

    expect($redirectUrl)->toBe('https://capell.test/login');
});

it('shows purchase guidance when marketplace requires checkout before installation', function (): void {
    config([
        'capell-marketplace.instance.id' => 'instance-123',
        'capell-marketplace.marketplace.webhook_secret' => 'test-secret',
    ]);

    Http::fake([
        'https://marketplace.test/api/extensions/paid-suite' => Http::response([
            'data' => marketplaceCatalogueExtensionPayload([
                'slug' => 'paid-suite',
                'name' => 'Paid Suite',
                'composer_name' => 'capell-app/paid-suite',
                'price_cents' => 9900,
                'is_paid' => true,
                'install_state' => 'purchase_required',
                'install_eligibility' => [
                    'state' => 'purchase_required',
                ],
                'purchase_url' => 'https://marketplace.test/checkout/paid-suite',
            ]),
        ]),
        'https://marketplace.test/api/extensions/paid-suite/install-authorization' => Http::response([
            'message' => 'Purchase this extension before installing.',
            'data' => [
                'purchase_url' => 'https://marketplace.test/checkout/paid-suite',
            ],
        ], 402),
    ]);

    (resolve(MarketplaceCatalogueTable::class))->installExtension(
        ['slug' => 'paid-suite'],
        ['email' => 'owner@example.test'],
    );

    $attempt = MarketplaceInstallAttempt::query()->sole();

    expect($attempt->status)->toBe(MarketplaceInstallIntentStatus::Blocked)
        ->and($attempt->failure_reason)->toBe('account_required');

    Http::assertNotSent(fn ($request): bool => $request->url() === 'https://marketplace.test/api/extensions/paid-suite/install-authorization');
});

it('blocks installs from marketplace eligibility before requesting authorization', function (): void {
    config([
        'capell-marketplace.instance.id' => 'instance-123',
        'capell-marketplace.marketplace.webhook_secret' => 'test-secret',
    ]);

    Http::fake([
        'https://marketplace.test/api/extensions/blocked-suite' => Http::response([
            'data' => marketplaceCatalogueExtensionPayload([
                'slug' => 'blocked-suite',
                'name' => 'Blocked Suite',
                'composer_name' => 'capell-app/blocked-suite',
                'requires_confirmation' => true,
                'install_eligibility' => [
                    'state' => 'blocked',
                    'block_reason' => 'public_verification_required',
                ],
            ]),
        ]),
        'https://marketplace.test/api/extensions/blocked-suite/install-authorization' => Http::response([
            'data' => [],
        ]),
    ]);

    (resolve(MarketplaceCatalogueTable::class))->installExtension(['slug' => 'blocked-suite']);

    $attempt = MarketplaceInstallAttempt::query()->sole();

    expect($attempt->status)->toBe(MarketplaceInstallIntentStatus::Blocked)
        ->and($attempt->failure_reason)->toBe('account_required');

    Http::assertNotSent(fn ($request): bool => $request->url() === 'https://marketplace.test/api/extensions/blocked-suite/install-authorization');
});

it('fails closed for protected installs when marketplace eligibility is missing', function (): void {
    config([
        'capell-marketplace.instance.id' => 'instance-123',
        'capell-marketplace.marketplace.webhook_secret' => 'test-secret',
    ]);

    Http::fake([
        'https://marketplace.test/api/extensions/protected-suite' => Http::response([
            'data' => marketplaceCatalogueExtensionPayload([
                'slug' => 'protected-suite',
                'name' => 'Protected Suite',
                'composer_name' => 'capell-app/protected-suite',
                'price_cents' => 4900,
                'is_paid' => true,
            ]),
        ]),
        'https://marketplace.test/api/extensions/protected-suite/install-authorization' => Http::response([
            'data' => [],
        ]),
    ]);

    (resolve(MarketplaceCatalogueTable::class))->installExtension(['slug' => 'protected-suite']);

    $attempt = MarketplaceInstallAttempt::query()->sole();

    expect($attempt->status)->toBe(MarketplaceInstallIntentStatus::Blocked)
        ->and($attempt->failure_reason)->toBe('account_required');

    Http::assertNotSent(fn ($request): bool => $request->url() === 'https://marketplace.test/api/extensions/protected-suite/install-authorization');
});

it('blocks protected installs for connected accounts with unverified email before authorization', function (): void {
    config([
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

    Http::fake([
        'https://marketplace.test/api/extensions/protected-unverified-suite' => Http::response([
            'data' => marketplaceCatalogueExtensionPayload([
                'slug' => 'protected-unverified-suite',
                'name' => 'Protected Unverified Suite',
                'composer_name' => 'capell-app/protected-unverified-suite',
                'price_cents' => 4900,
                'is_paid' => true,
                'install_eligibility' => [
                    'state' => 'authorized',
                    'can_install' => true,
                ],
            ]),
        ]),
        'https://marketplace.test/api/extensions/protected-unverified-suite/install-authorization' => Http::response([
            'data' => [],
        ]),
    ]);

    (resolve(MarketplaceCatalogueTable::class))->installExtension(['slug' => 'protected-unverified-suite']);

    $attempt = MarketplaceInstallAttempt::query()->sole();

    expect($attempt->status)->toBe(MarketplaceInstallIntentStatus::Blocked)
        ->and($attempt->failure_reason)->toBe('email_verification_required');

    Http::assertNotSent(fn ($request): bool => $request->url() === 'https://marketplace.test/api/extensions/protected-unverified-suite/install-authorization');
});

it('fails closed for protected installs with legacy install state when marketplace eligibility is missing', function (): void {
    config([
        'capell-marketplace.instance.id' => 'instance-123',
        'capell-marketplace.marketplace.webhook_secret' => 'test-secret',
    ]);

    Http::fake([
        'https://marketplace.test/api/extensions/protected-legacy-suite' => Http::response([
            'data' => marketplaceCatalogueExtensionPayload([
                'slug' => 'protected-legacy-suite',
                'name' => 'Protected Legacy Suite',
                'composer_name' => 'capell-app/protected-legacy-suite',
                'price_cents' => 4900,
                'is_paid' => true,
                'install_state' => 'free_available',
            ]),
        ]),
        'https://marketplace.test/api/extensions/protected-legacy-suite/install-authorization' => Http::response([
            'data' => [],
        ]),
    ]);

    (resolve(MarketplaceCatalogueTable::class))->installExtension(['slug' => 'protected-legacy-suite']);

    $attempt = MarketplaceInstallAttempt::query()->sole();

    expect($attempt->status)->toBe(MarketplaceInstallIntentStatus::Blocked)
        ->and($attempt->failure_reason)->toBe('account_required');

    Http::assertNotSent(fn ($request): bool => $request->url() === 'https://marketplace.test/api/extensions/protected-legacy-suite/install-authorization');
});

it('installs confirmation-required free extensions without account authorization when marketplace eligibility is missing', function (): void {
    config([
        'capell-marketplace.instance.id' => 'instance-123',
        'capell-marketplace.marketplace.webhook_secret' => 'test-secret',
    ]);

    Http::fake([
        'https://marketplace.test/api/extensions/confirmation-suite' => Http::response([
            'data' => marketplaceCatalogueExtensionPayload([
                'slug' => 'confirmation-suite',
                'name' => 'Confirmation Suite',
                'composer_name' => 'capell-app/confirmation-suite',
                'requires_confirmation' => true,
                'install_state' => 'free_available',
            ]),
        ]),
        'https://marketplace.test/api/extensions/confirmation-suite/install-authorization' => Http::response([
            'data' => [],
        ]),
        'https://marketplace.test/api/extensions/install-intents' => Http::response(['data' => ['recorded' => true]]),
    ]);

    (resolve(MarketplaceCatalogueTable::class))->installExtension(['slug' => 'confirmation-suite']);

    $attempt = MarketplaceInstallAttempt::query()->sole();

    expect($attempt->status)->toBe(MarketplaceInstallIntentStatus::Queued)
        ->and($attempt->failure_reason)->toBeNull();

    Http::assertNotSent(fn ($request): bool => $request->url() === 'https://marketplace.test/api/extensions/confirmation-suite/install-authorization');
});

it('prefers marketplace eligibility policy over legacy install state when installing', function (): void {
    config([
        'capell-marketplace.instance.id' => 'instance-123',
        'capell-marketplace.marketplace.webhook_secret' => 'test-secret',
    ]);

    Http::fake([
        'https://marketplace.test/api/extensions/policy-suite' => Http::response([
            'data' => marketplaceCatalogueExtensionPayload([
                'slug' => 'policy-suite',
                'name' => 'Policy Suite',
                'composer_name' => 'capell-app/policy-suite',
                'requires_confirmation' => true,
                'install_state' => 'free_available',
                'install_eligibility' => [
                    'state' => 'blocked',
                    'block_reason' => 'domain_verification_required',
                ],
            ]),
        ]),
        'https://marketplace.test/api/extensions/policy-suite/install-authorization' => Http::response([
            'data' => [],
        ]),
    ]);

    (resolve(MarketplaceCatalogueTable::class))->installExtension(['slug' => 'policy-suite']);

    $attempt = MarketplaceInstallAttempt::query()->sole();

    expect($attempt->status)->toBe(MarketplaceInstallIntentStatus::Blocked)
        ->and($attempt->failure_reason)->toBe('account_required');

    Http::assertNotSent(fn ($request): bool => $request->url() === 'https://marketplace.test/api/extensions/policy-suite/install-authorization');
});

it('records failed authorization attempts in the marketplace install ledger', function (): void {
    config([
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
        'https://marketplace.test/api/extensions/broken-suite' => Http::response([
            'data' => marketplaceCatalogueExtensionPayload([
                'slug' => 'broken-suite',
                'name' => 'Broken Suite',
                'composer_name' => 'capell-app/broken-suite',
                'kind' => 'tool',
                'is_paid' => true,
                'price_cents' => 9900,
                'install_eligibility' => [
                    'state' => 'authorized',
                    'can_install' => true,
                ],
            ]),
        ]),
        'https://marketplace.test/api/extensions/broken-suite/install-authorization' => Http::response([
            'message' => 'Marketplace authorization failed.',
        ], 500),
    ]);

    (resolve(MarketplaceCatalogueTable::class))->installExtension(['slug' => 'broken-suite']);

    $intent = MarketplaceInstallAttempt::query()->sole();

    expect($intent->extension_slug)->toBe('broken-suite')
        ->and($intent->composer_name)->toBe('capell-app/broken-suite')
        ->and($intent->status)->toBe(MarketplaceInstallIntentStatus::AuthorizationFailed)
        ->and($intent->failure_reason)->toBe('Marketplace authorization failed.');
});

it('blocks installs when authorization response returns blocking eligibility', function (): void {
    config([
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
        'https://marketplace.test/api/extensions/auth-blocked-suite' => Http::response([
            'data' => marketplaceCatalogueExtensionPayload([
                'slug' => 'auth-blocked-suite',
                'name' => 'Auth Blocked Suite',
                'composer_name' => 'capell-app/auth-blocked-suite',
                'kind' => 'tool',
                'is_paid' => true,
                'price_cents' => 9900,
                'install_eligibility' => [
                    'state' => 'authorized',
                    'can_install' => true,
                ],
            ]),
        ]),
        'https://marketplace.test/api/extensions/auth-blocked-suite/install-authorization' => Http::response([
            'data' => [
                'composer_name' => 'capell-app/auth-blocked-suite',
                'version_constraint' => '^1.0',
                'install_eligibility' => [
                    'state' => 'blocked',
                    'block_reason' => 'activation_required',
                ],
            ],
        ]),
        'https://marketplace.test/api/extensions/install-intents' => Http::response(['data' => ['recorded' => true]]),
    ]);

    (resolve(MarketplaceCatalogueTable::class))->installExtension(['slug' => 'auth-blocked-suite']);

    $attempt = MarketplaceInstallAttempt::query()->sole();

    expect($attempt->status)->toBe(MarketplaceInstallIntentStatus::Blocked)
        ->and($attempt->failure_reason)->toBe('activation_required');

    Http::assertNotSent(fn ($request): bool => $request->url() === 'https://marketplace.test/api/extensions/install-intents');
});

it('bypasses cached extension detail when enforcing install eligibility', function (): void {
    config([
        'capell-marketplace.instance.id' => 'instance-123',
        'capell-marketplace.marketplace.webhook_secret' => 'test-secret',
    ]);

    Http::fake([
        'https://marketplace.test/api/extensions/cache-suite' => Http::sequence()
            ->push(['data' => marketplaceCatalogueExtensionPayload([
                'slug' => 'cache-suite',
                'name' => 'Cache Suite',
                'composer_name' => 'capell-app/cache-suite',
            ])])
            ->push(['data' => marketplaceCatalogueExtensionPayload([
                'slug' => 'cache-suite',
                'name' => 'Cache Suite',
                'composer_name' => 'capell-app/cache-suite',
                'requires_confirmation' => true,
                'install_eligibility' => [
                    'state' => 'blocked',
                    'block_reason' => 'domain_verification_required',
                ],
            ])]),
        'https://marketplace.test/api/extensions/cache-suite/install-authorization' => Http::response([
            'data' => [],
        ]),
    ]);

    resolve(MarketplaceClient::class)->getExtension('cache-suite');
    (resolve(MarketplaceCatalogueTable::class))->installExtension(['slug' => 'cache-suite']);

    $attempt = MarketplaceInstallAttempt::query()->sole();

    expect($attempt->status)->toBe(MarketplaceInstallIntentStatus::Blocked)
        ->and($attempt->failure_reason)->toBe('account_required');

    Http::assertSentCount(2);
});

it('refreshes selected marketplace installs by composer name when available', function (): void {
    Http::fake([
        'https://marketplace.test/api/extensions/fragile-suite' => Http::response(['message' => 'Detail endpoint unavailable'], 500),
        'https://marketplace.test/api/extensions/by-composer*' => Http::response([
            'data' => [
                marketplaceCatalogueExtensionPayload([
                    'slug' => 'fragile-suite',
                    'name' => 'Fragile Suite',
                    'composer_name' => 'capell-app/fragile-suite',
                    'install_eligibility' => [
                        'state' => 'blocked',
                        'block_reason' => 'domain_verification_required',
                    ],
                ]),
            ],
        ]),
    ]);

    (resolve(MarketplaceCatalogueTable::class))->installExtension([
        'slug' => 'fragile-suite',
        'composer_name' => 'capell-app/fragile-suite',
    ]);

    $attempt = MarketplaceInstallAttempt::query()->sole();

    expect($attempt->status)->toBe(MarketplaceInstallIntentStatus::Blocked)
        ->and($attempt->composer_name)->toBe('capell-app/fragile-suite');

    Http::assertNotSent(fn ($request): bool => $request->url() === 'https://marketplace.test/api/extensions/fragile-suite');
    Http::assertSent(fn ($request): bool => str_starts_with((string) $request->url(), 'https://marketplace.test/api/extensions/by-composer?')
        && str_contains((string) $request->url(), 'composer_names=capell-app%2Ffragile-suite'));
});

it('always requests live marketplace authorization when installing a paid extension from cached detail', function (): void {
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
        'https://marketplace.test/api/extensions/paid-cache-suite' => Http::sequence()
            ->push(['data' => marketplaceCatalogueExtensionPayload([
                'slug' => 'paid-cache-suite',
                'name' => 'Paid Cache Suite',
                'composer_name' => 'capell-app/paid-cache-suite',
                'is_paid' => false,
            ])])
            ->push(['data' => marketplaceCatalogueExtensionPayload([
                'slug' => 'paid-cache-suite',
                'name' => 'Paid Cache Suite',
                'composer_name' => 'capell-app/paid-cache-suite',
                'is_paid' => true,
                'price_cents' => 9900,
                'install_eligibility' => [
                    'state' => 'authorized',
                    'can_install' => true,
                ],
            ])]),
        'https://marketplace.test/api/extensions/paid-cache-suite/install-authorization' => Http::response([
            'data' => [
                'composer_name' => 'capell-app/paid-cache-suite',
                'version_constraint' => '^2.0',
                'install_eligibility' => [
                    'state' => 'authorized',
                    'can_install' => true,
                ],
            ],
        ]),
        'https://marketplace.test/api/extensions/install-intents' => Http::response(['data' => ['recorded' => true]]),
    ]);

    resolve(MarketplaceClient::class)->getExtension('paid-cache-suite');

    (resolve(MarketplaceCatalogueTable::class))->installExtension(['slug' => 'paid-cache-suite']);

    $attempt = MarketplaceInstallAttempt::query()->sole();

    expect($attempt->status)->toBe(MarketplaceInstallIntentStatus::Queued)
        ->and($attempt->composer_name)->toBe('capell-app/paid-cache-suite')
        ->and($attempt->version_constraint)->toBe('^2.0');

    Http::assertSent(fn ($request): bool => $request->url() === 'https://marketplace.test/api/extensions/paid-cache-suite');
    Http::assertSent(fn ($request): bool => $request->url() === 'https://marketplace.test/api/extensions/paid-cache-suite/install-authorization');
});

it('records append-only install attempts for repeated marketplace install attempts', function (): void {
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
        'https://marketplace.test/api/extensions/seo-suite' => Http::response([
            'data' => marketplaceCatalogueExtensionPayload([
                'slug' => 'seo-suite',
                'name' => 'SEO Suite',
                'composer_name' => 'capell-app/seo-suite',
                'kind' => 'tool',
                'is_paid' => true,
                'price_cents' => 9900,
                'install_eligibility' => [
                    'state' => 'authorized',
                    'can_install' => true,
                ],
            ]),
        ]),
        'https://marketplace.test/api/extensions/seo-suite/install-authorization' => Http::sequence()
            ->push(['message' => 'Marketplace authorization failed.'], 500)
            ->push(['data' => [
                'composer_name' => 'capell-app/seo-suite',
                'version_constraint' => '^2.1',
            ]]),
        'https://marketplace.test/api/extensions/install-intents' => Http::response(['data' => ['recorded' => true]]),
    ]);

    (resolve(MarketplaceCatalogueTable::class))->installExtension(['slug' => 'seo-suite']);
    (resolve(MarketplaceCatalogueTable::class))->installExtension(['slug' => 'seo-suite']);

    expect(MarketplaceInstallAttempt::query()->count())->toBe(2)
        ->and(MarketplaceInstallAttempt::query()->pluck('status')->all())->toEqual([
            MarketplaceInstallIntentStatus::AuthorizationFailed,
            MarketplaceInstallIntentStatus::Queued,
        ]);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function marketplaceCatalogueExtensionPayload(array $overrides = []): array
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
 * @return array<int, string>
 */
function filamentNotificationTitles(): array
{
    return collect(session('filament.notifications', []))
        ->pluck('title')
        ->all();
}

function marketplaceQueueListing(): ExtensionListingData
{
    return new ExtensionListingData(
        slug: 'seo-suite',
        name: 'SEO Suite',
        composerName: 'capell-app/seo-suite',
        kind: 'tool',
        description: 'SEO tools.',
        priceCents: 0,
        isPaid: false,
        forkRepoUrl: null,
        productId: null,
        latestVersion: '2.1.0',
    );
}

function marketplaceQueueAcquisition(): ExtensionAcquisitionData
{
    return new ExtensionAcquisitionData(
        composerName: 'capell-app/seo-suite',
        versionConstraint: '^2.1',
        composerCommand: 'composer require capell-app/seo-suite:^2.1',
        repositoryUrl: null,
        purchaseUrl: null,
        requiresDeployment: true,
    );
}

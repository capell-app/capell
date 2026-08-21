<?php

declare(strict_types=1);

use Capell\Core\Facades\CapellCore;
use Capell\Marketplace\Actions\BuildMarketplaceCatalogueLocalStateSnapshotAction;
use Capell\Marketplace\Actions\FetchMarketplaceCataloguePageAction;
use Capell\Marketplace\Actions\ProjectMarketplaceCatalogueRecordAction;
use Capell\Marketplace\Actions\QueryMarketplaceInstallProgressAction;
use Capell\Marketplace\Actions\ResolveMarketplaceCatalogueLocalStateAction;
use Capell\Marketplace\Actions\StartReviewedMarketplaceSelectionAction;
use Capell\Marketplace\Data\ExtensionListingData;
use Capell\Marketplace\Data\MarketplaceCatalogueLocalStateData;
use Capell\Marketplace\Data\MarketplaceCatalogueQueryData;
use Capell\Marketplace\Data\MarketplaceEnvironmentReadinessData;
use Capell\Marketplace\Data\MarketplaceInstallActorData;
use Capell\Marketplace\Data\MarketplaceInstallProgressQueryData;
use Capell\Marketplace\Data\MarketplaceReviewedSelectionInputData;
use Capell\Marketplace\Data\MarketplaceSelectionRecordData;
use Capell\Marketplace\Data\MarketplaceSelectionReviewData;
use Capell\Marketplace\Enums\MarketplaceInstallCapability;
use Capell\Marketplace\Enums\MarketplaceInstallFailureStage;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Enums\MarketplaceReviewedSelectionOutcome;
use Capell\Marketplace\Filament\Support\MarketplaceCatalogueRecordPresenter;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(CreatesAdminUser::class);

beforeEach(function (): void {
    Cache::flush();
    test()->actingAsAdmin();

    config([
        'capell-marketplace.marketplace.base_url' => 'https://marketplace.test/api',
        'capell-marketplace.marketplace.web_url' => 'https://marketplace.test',
        'capell-marketplace.marketplace.cache_ttl_seconds' => 300,
    ]);
});

it('returns typed remote catalogue success and unavailable outcomes', function (): void {
    Http::fake(static fn (Request $request) => ($request->data()['search'] ?? null) === 'unavailable'
        ? Http::response([], 503)
        : Http::response([
            'data' => [marketplaceBoundaryExtensionPayload()],
            'meta' => ['total' => 1, 'current_page' => 1, 'per_page' => 18],
            'links' => ['next' => null],
        ]));

    $query = new MarketplaceCatalogueQueryData(page: 1, perPage: 18);
    $available = FetchMarketplaceCataloguePageAction::run($query);

    expect($available->isUnavailable())->toBeFalse()
        ->and($available->page->extensions)->toHaveCount(1)
        ->and($available->page->extensions[0])->toBeInstanceOf(ExtensionListingData::class);

    $unavailable = FetchMarketplaceCataloguePageAction::run(new MarketplaceCatalogueQueryData(
        search: 'unavailable',
        page: 1,
        perPage: 18,
    ));

    expect($unavailable->isUnavailable())->toBeTrue()
        ->and($unavailable->page->extensions)->toBe([])
        ->and($unavailable->unavailableReason)->not->toBeNull();
});

it('enriches local catalogue state from one active-operation query', function (): void {
    CapellCore::registerPackage('capell-app/seo-suite', version: '1.0.0');
    CapellCore::forcePackageInstalled('capell-app/seo-suite');

    $attempt = MarketplaceInstallAttempt::query()->create([
        'composer_name' => 'capell-app/seo-suite',
        'extension_slug' => 'seo-suite',
        'extension_name' => 'SEO Suite',
        'kind' => 'tool',
        'status' => MarketplaceInstallIntentStatus::Running,
        'current_stage' => MarketplaceInstallFailureStage::Composer->value,
        'queued_at' => now(),
    ]);
    $operationQueries = 0;

    DB::listen(function (QueryExecuted $query) use (&$operationQueries): void {
        if (str_starts_with(strtolower($query->sql), 'select')
            && str_contains($query->sql, 'marketplace_install_attempts')) {
            $operationQueries++;
        }
    });

    $snapshot = BuildMarketplaceCatalogueLocalStateSnapshotAction::run();
    $listing = ExtensionListingData::fromApiResponse(marketplaceBoundaryExtensionPayload([
        'latest_version' => '1.1.0',
    ]));
    $state = ResolveMarketplaceCatalogueLocalStateAction::run($listing, $snapshot);

    expect($snapshot->downloadedComposerNames)->toContain('capell-app/seo-suite')
        ->and($state->isInstalled)->toBeTrue()
        ->and($state->installedVersion)->toBe('1.0.0')
        ->and($state->hasUpdateAvailable)->toBeTrue()
        ->and($state->activeOperationId)->toBe((int) $attempt->getKey())
        ->and($state->activeOperationStatus)->toBe(MarketplaceInstallIntentStatus::Running)
        ->and($operationQueries)->toBe(1);
});

it('keeps raw catalogue projection separate from translated Filament presentation', function (): void {
    $listing = ExtensionListingData::fromApiResponse(marketplaceBoundaryExtensionPayload([
        'is_paid' => true,
        'price_cents' => 1200,
        'currency' => 'USD',
        'categories' => ['seo'],
        'capabilities' => ['settings' => true],
        'documentation_url' => 'https://marketplace.test/docs/seo-suite',
        'purchase_url' => 'https://marketplace.test/extensions/seo-suite',
    ]));
    $projection = ProjectMarketplaceCatalogueRecordAction::run(
        listing: $listing,
        localState: MarketplaceCatalogueLocalStateData::withoutLocalState(),
        instance: null,
    );

    expect($projection->listing)->toBe($listing)
        ->and($projection->isCompatible)->toBeTrue()
        ->and($projection->compatibilityDetails)->toHaveKeys(['capell', 'laravel', 'filament', 'livewire'])
        ->and($projection->documentationUrl)->toBe('https://marketplace.test/docs/seo-suite')
        ->and($projection->purchaseUrl)->toBe('https://marketplace.test/extensions/seo-suite');

    $presented = resolve(MarketplaceCatalogueRecordPresenter::class)->present($projection);

    expect($presented)->toMatchArray([
        'composer_name' => 'capell-app/seo-suite',
        'price_cents' => 1200,
        'is_installed' => false,
        'is_compatible' => true,
    ])->and($presented['price_label'])->toBeString()
        ->and($presented['category_labels'])->toBe(['SEO'])
        ->and($presented['capability_labels'])->toBe(['Settings']);
});

it('queries raw install progress in one read for UI translation', function (): void {
    $running = MarketplaceInstallAttempt::query()->create([
        'composer_name' => 'capell-app/seo-suite',
        'extension_slug' => 'seo-suite',
        'extension_name' => 'SEO Suite',
        'kind' => 'tool',
        'status' => MarketplaceInstallIntentStatus::Running,
        'current_stage' => MarketplaceInstallFailureStage::HealthCheck->value,
        'progress_current' => 4,
        'progress_total' => 5,
        'queued_at' => now(),
    ]);
    $progressQueries = 0;

    DB::listen(function (QueryExecuted $query) use (&$progressQueries): void {
        if (str_starts_with(strtolower($query->sql), 'select')
            && str_contains($query->sql, 'marketplace_install_attempts')) {
            $progressQueries++;
        }
    });

    $result = QueryMarketplaceInstallProgressAction::run(
        MarketplaceInstallProgressQueryData::forAttemptIds([(int) $running->getKey()]),
    );

    expect($result->items)->toHaveCount(1)
        ->and($result->items[0]->status)->toBe(MarketplaceInstallIntentStatus::Running)
        ->and($result->items[0]->stage)->toBe(MarketplaceInstallFailureStage::HealthCheck->value)
        ->and($result->items[0]->isActive())->toBeTrue()
        ->and($progressQueries)->toBe(1);
});

it('returns typed rejection and licence-validation outcomes before install work starts', function (): void {
    $review = marketplaceBoundarySelectionReview([
        'is_paid' => true,
        'marketplace_install_state' => 'activation_required',
        'install_eligibility_policy' => ['state' => 'activation_required'],
    ], requiresPremiumFlow: true);
    $baseInput = [
        'selection' => $review,
        'readiness' => new MarketplaceEnvironmentReadinessData(MarketplaceInstallCapability::Automated),
        'betaAcknowledged' => true,
        'activateThemesAfterInstall' => false,
        'selectedInstallOptions' => [],
        'actor' => MarketplaceInstallActorData::system('marketplace-boundary-test'),
        'returnUrl' => 'https://capell.test/admin/marketplace/install-flow/callback',
    ];

    $rejected = StartReviewedMarketplaceSelectionAction::run(new MarketplaceReviewedSelectionInputData(
        ...$baseInput,
        confirmed: false,
        licenseKey: null,
    ));
    $licenceRequired = StartReviewedMarketplaceSelectionAction::run(new MarketplaceReviewedSelectionInputData(
        ...$baseInput,
        confirmed: true,
        licenseKey: null,
    ));

    expect($rejected->outcome)->toBe(MarketplaceReviewedSelectionOutcome::Rejected)
        ->and($licenceRequired->outcome)->toBe(MarketplaceReviewedSelectionOutcome::LicenceValidationFailed)
        ->and($licenceRequired->licenceValidationRule)->toBe('required')
        ->and(MarketplaceInstallAttempt::query()->count())->toBe(0);
});

it('returns purchase fallback and presentable failure outcomes from hosted flow errors', function (): void {
    Http::fake([
        '*' => Http::response([], 503),
    ]);

    $fallback = StartReviewedMarketplaceSelectionAction::run(marketplaceBoundaryReviewedSelectionInput(
        marketplaceBoundarySelectionReview([
            'is_paid' => true,
            'purchase_url' => 'https://marketplace.test/extensions/seo-suite',
        ], requiresPremiumFlow: true),
    ));
    $failure = StartReviewedMarketplaceSelectionAction::run(marketplaceBoundaryReviewedSelectionInput(
        marketplaceBoundarySelectionReview([
            'is_paid' => true,
            'purchase_url' => null,
        ], requiresPremiumFlow: true),
    ));

    expect($fallback->outcome)->toBe(MarketplaceReviewedSelectionOutcome::PurchaseFallback)
        ->and($fallback->redirectUrl)->toBe('https://marketplace.test/extensions/seo-suite')
        ->and($failure->outcome)->toBe(MarketplaceReviewedSelectionOutcome::PresentableFailure)
        ->and($failure->failure)->toBeInstanceOf(Throwable::class);
});

/** @param array<string, mixed> $overrides */
function marketplaceBoundaryExtensionPayload(array $overrides = []): array
{
    return [
        'slug' => 'seo-suite',
        'name' => 'SEO Suite',
        'composer_name' => 'capell-app/seo-suite',
        'kind' => 'tool',
        'description' => 'SEO tools.',
        'price_cents' => 0,
        'is_paid' => false,
        'latest_version' => '1.0.0',
        ...$overrides,
    ];
}

/** @param array<string, mixed> $payload */
function marketplaceBoundarySelectionReview(array $payload = [], bool $requiresPremiumFlow = false): MarketplaceSelectionReviewData
{
    $record = MarketplaceSelectionRecordData::fromPayload(marketplaceBoundaryExtensionPayload($payload))
        ->withPolicy(requiresPremiumFlow: $requiresPremiumFlow, failureReasonCode: null);

    return new MarketplaceSelectionReviewData(
        explicitRecords: [$record],
        dependencyRecords: [],
        installRecords: [$record],
        installComposerNames: ['capell-app/seo-suite'],
        dependencyComposerNames: [],
        missingDependencies: [],
        blockedDependencies: [],
        premiumRecords: $requiresPremiumFlow ? [$record] : [],
        selectedCount: 1,
        installCount: 1,
        totalCents: $record->priceCents,
        hasPremiumRecords: $requiresPremiumFlow,
        containsBeta: false,
        betaDependencyComposerNames: [],
        impactRecords: [],
        canInstall: true,
    );
}

function marketplaceBoundaryReviewedSelectionInput(MarketplaceSelectionReviewData $review): MarketplaceReviewedSelectionInputData
{
    return new MarketplaceReviewedSelectionInputData(
        selection: $review,
        readiness: new MarketplaceEnvironmentReadinessData(MarketplaceInstallCapability::Automated),
        confirmed: true,
        betaAcknowledged: true,
        licenseKey: null,
        activateThemesAfterInstall: false,
        selectedInstallOptions: [],
        actor: MarketplaceInstallActorData::system('marketplace-boundary-test'),
        returnUrl: 'https://capell.test/admin/marketplace/install-flow/callback',
    );
}

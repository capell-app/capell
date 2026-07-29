<?php

declare(strict_types=1);

use Capell\Marketplace\Actions\BuildMarketplaceSelectionReviewAction;
use Capell\Marketplace\Data\MarketplaceSelectionInputData;
use Capell\Marketplace\Data\MarketplaceSelectionRecordData;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Cache::flush();

    config([
        'capell-marketplace.marketplace.base_url' => 'https://marketplace.test/api',
        'capell-marketplace.marketplace.cache_ttl_seconds' => 300,
    ]);
});

it('returns stable reason codes for every Marketplace selection policy branch', function (
    array $payload,
    bool $canManageExtensions,
    ?string $expectedReasonCode,
    bool $requiresPremiumFlow,
): void {
    $record = resolve(BuildMarketplaceSelectionReviewAction::class)->record(
        payload: marketplaceSelectionPolicyRecord($payload),
        canManageExtensions: $canManageExtensions,
    );

    expect($record->failureReasonCode)->toBe($expectedReasonCode)
        ->and($record->isSelectable())->toBe($expectedReasonCode === null)
        ->and($record->requiresPremiumFlow)->toBe($requiresPremiumFlow);
})->with([
    'available' => [[], true, null, false],
    'installed' => [
        ['is_installed' => true],
        true,
        MarketplaceSelectionRecordData::FAILURE_INSTALLED,
        false,
    ],
    'install in progress' => [
        ['install_in_progress' => true],
        true,
        MarketplaceSelectionRecordData::FAILURE_INSTALL_IN_PROGRESS,
        false,
    ],
    'incompatible' => [
        ['is_compatible' => false],
        true,
        MarketplaceSelectionRecordData::FAILURE_INCOMPATIBLE,
        false,
    ],
    'server-state incompatible' => [
        ['marketplace_install_state' => 'incompatible'],
        true,
        MarketplaceSelectionRecordData::FAILURE_INCOMPATIBLE,
        false,
    ],
    'eligibility-policy incompatible' => [
        [
            'marketplace_install_state' => 'incompatible',
            'install_eligibility_policy' => ['state' => 'incompatible'],
        ],
        true,
        MarketplaceSelectionRecordData::FAILURE_INCOMPATIBLE,
        false,
    ],
    'permission' => [
        [],
        false,
        MarketplaceSelectionRecordData::FAILURE_PERMISSION,
        false,
    ],
    'blocked eligibility' => [
        [
            'marketplace_install_state' => 'blocked',
            'install_eligibility_policy' => [
                'state' => 'blocked',
                'block_reason' => 'entitlement_required',
            ],
        ],
        true,
        'entitlement_required',
        false,
    ],
    'generic block' => [
        ['marketplace_install_state' => 'blocked'],
        true,
        MarketplaceSelectionRecordData::FAILURE_UNAVAILABLE,
        false,
    ],
    'paid account flow' => [
        [
            'is_paid' => true,
            'price_cents' => 4900,
            'marketplace_install_state' => 'blocked',
            'install_eligibility_policy' => [
                'state' => 'blocked',
                'block_reason' => 'account_required',
            ],
        ],
        true,
        null,
        true,
    ],
    'email verification flow' => [
        [
            'activation_required' => true,
            'marketplace_install_state' => 'blocked',
            'install_eligibility_policy' => [
                'state' => 'blocked',
                'block_reason' => 'email_verification_required',
            ],
        ],
        true,
        null,
        true,
    ],
    'not connected flow' => [
        [
            'is_paid' => true,
            'marketplace_install_state' => 'blocked',
            'install_eligibility_policy' => [
                'state' => 'blocked',
                'block_reason' => 'not_connected',
            ],
        ],
        true,
        null,
        true,
    ],
    'Capell All flow' => [
        [
            'marketplace_install_state' => 'blocked',
            'install_eligibility_policy' => [
                'state' => 'blocked',
                'block_reason' => 'capell_all_required',
            ],
        ],
        true,
        null,
        true,
    ],
    'purchase flow' => [
        ['marketplace_install_state' => 'purchase_required'],
        true,
        null,
        true,
    ],
]);

it('preserves explicit ordering while expanding a dependency cycle once', function (): void {
    fakeMarketplaceSelectionPolicyCatalogue([
        marketplaceSelectionPolicyPayload(
            composerName: 'vendor/first',
            overrides: [
                'name' => 'First',
                'price_cents' => 1000,
                'dependencies' => ['requires' => ['vendor/dependency']],
            ],
        ),
        marketplaceSelectionPolicyPayload(
            composerName: 'vendor/second',
            overrides: ['name' => 'Second'],
        ),
        marketplaceSelectionPolicyPayload(
            composerName: 'vendor/dependency',
            overrides: [
                'name' => 'Dependency',
                'price_cents' => 250,
                'maturity' => 'beta',
                'maturity_label' => 'Beta',
                'dependencies' => ['requires' => ['vendor/first']],
            ],
        ),
    ]);

    $review = BuildMarketplaceSelectionReviewAction::run(MarketplaceSelectionInputData::make(
        selectedComposerNames: ['vendor/first', 'vendor/second'],
        lockedKind: null,
        includeLocalExtensionState: true,
        canManageExtensions: true,
    ));

    expect($review->explicitRecords)->toHaveCount(2)
        ->and(array_map(
            static fn (MarketplaceSelectionRecordData $record): ?string => $record->composerName,
            $review->explicitRecords,
        ))->toBe(['vendor/first', 'vendor/second'])
        ->and($review->dependencyComposerNames)->toBe(['vendor/dependency'])
        ->and($review->installComposerNames)->toBe([
            'vendor/first',
            'vendor/second',
            'vendor/dependency',
        ])
        ->and($review->totalCents)->toBe(1250)
        ->and($review->containsBeta)->toBeTrue()
        ->and($review->betaDependencyComposerNames)->toBe(['vendor/dependency'])
        ->and($review->hasPremiumRecords)->toBeTrue()
        ->and($review->canInstall)->toBeTrue();
});

it('reports missing and blocked dependencies without making the selection installable', function (): void {
    fakeMarketplaceSelectionPolicyCatalogue([
        marketplaceSelectionPolicyPayload(
            composerName: 'vendor/selected',
            overrides: [
                'dependencies' => [
                    'requires' => ['vendor/missing', 'vendor/busy'],
                ],
            ],
        ),
        marketplaceSelectionPolicyPayload(
            composerName: 'vendor/busy',
            overrides: [
                'name' => 'Busy dependency',
                'laravel_version_constraint' => '<1.0',
            ],
        ),
    ]);

    $review = BuildMarketplaceSelectionReviewAction::run(MarketplaceSelectionInputData::make(
        selectedComposerNames: ['vendor/selected'],
        lockedKind: null,
        includeLocalExtensionState: true,
        canManageExtensions: true,
    ));

    expect($review->missingDependencies)->toBe(['vendor/missing'])
        ->and($review->blockedDependencies)->toHaveCount(1)
        ->and($review->blockedDependencies[0]->composerName)->toBe('vendor/busy')
        ->and($review->blockedDependencies[0]->failureReasonCode)
        ->toBe(MarketplaceSelectionRecordData::FAILURE_INCOMPATIBLE)
        ->and($review->installComposerNames)->toBe(['vendor/selected'])
        ->and($review->canInstall)->toBeFalse();
});

it('treats Marketplace-managed and installed Composer dependencies as already satisfied', function (): void {
    fakeMarketplaceSelectionPolicyCatalogue([
        marketplaceSelectionPolicyPayload(
            composerName: 'vendor/selected',
            overrides: [
                'dependencies' => [
                    'requires' => [
                        'capell-app/block-library',
                        'capell-app/core',
                    ],
                ],
            ],
        ),
    ]);

    $review = BuildMarketplaceSelectionReviewAction::run(MarketplaceSelectionInputData::make(
        selectedComposerNames: ['vendor/selected'],
        lockedKind: null,
        includeLocalExtensionState: true,
        canManageExtensions: true,
    ));

    expect($review->dependencyRecords)->toBe([])
        ->and($review->missingDependencies)->toBe([])
        ->and($review->canInstall)->toBeTrue();
});

it('enforces extension management permission in direct review policy', function (): void {
    fakeMarketplaceSelectionPolicyCatalogue([
        marketplaceSelectionPolicyPayload(composerName: 'vendor/selected'),
    ]);

    $review = BuildMarketplaceSelectionReviewAction::run(MarketplaceSelectionInputData::make(
        selectedComposerNames: ['vendor/selected'],
        lockedKind: null,
        includeLocalExtensionState: false,
        canManageExtensions: false,
    ));

    expect($review->explicitRecords)->toBe([])
        ->and($review->installRecords)->toBe([])
        ->and($review->selectedCount)->toBe(0)
        ->and($review->canInstall)->toBeFalse();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function marketplaceSelectionPolicyRecord(array $overrides = []): array
{
    return [
        'slug' => 'selection-policy',
        'name' => 'Selection Policy',
        'composer_name' => 'vendor/selection-policy',
        'kind' => 'package',
        'price_cents' => 0,
        'is_paid' => false,
        'is_compatible' => true,
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function marketplaceSelectionPolicyPayload(string $composerName, array $overrides = []): array
{
    return [
        'slug' => str_replace('/', '-', $composerName),
        'name' => str($composerName)->after('/')->headline()->toString(),
        'composer_name' => $composerName,
        'kind' => 'package',
        'description' => 'Selection policy fixture.',
        'price_cents' => 0,
        'is_paid' => false,
        'latest_version' => '1.0.0',
        'catalogue_role' => 'extension',
        'maturity' => 'stable',
        'maturity_label' => 'Released',
        'included_with_capell_all' => false,
        ...$overrides,
    ];
}

/** @param list<array<string, mixed>> $payloads */
function fakeMarketplaceSelectionPolicyCatalogue(array $payloads): void
{
    Http::fake(function (Request $request) use ($payloads) {
        if (str_contains($request->url(), '/extensions/by-composer')) {
            $composerNames = explode(',', (string) ($request->data()['composer_names'] ?? ''));

            return Http::response([
                'data' => array_values(array_filter(
                    $payloads,
                    static fn (array $payload): bool => in_array(
                        $payload['composer_name'] ?? null,
                        $composerNames,
                        true,
                    ),
                )),
            ]);
        }

        if (str_contains($request->url(), '/extensions')) {
            $search = (string) ($request->data()['search'] ?? '');

            return Http::response([
                'data' => array_values(array_filter(
                    $payloads,
                    static fn (array $payload): bool => ($payload['composer_name'] ?? null) === $search,
                )),
                'links' => ['next' => null],
            ]);
        }

        return Http::response([], 404);
    });
}

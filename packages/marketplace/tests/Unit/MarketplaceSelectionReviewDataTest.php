<?php

declare(strict_types=1);

use Capell\Marketplace\Data\MarketplaceSelectionBlockedDependencyData;
use Capell\Marketplace\Data\MarketplaceSelectionInputData;
use Capell\Marketplace\Data\MarketplaceSelectionRecordData;
use Capell\Marketplace\Data\MarketplaceSelectionReviewData;

it('normalizes Marketplace selection input without changing selection order', function (): void {
    $input = MarketplaceSelectionInputData::make(
        selectedComposerNames: [
            ' vendor/first ',
            '',
            null,
            'vendor/second',
            'vendor/first',
        ],
        lockedKind: 'theme',
        includeLocalExtensionState: false,
        canManageExtensions: true,
    );

    expect($input->selectedComposerNames)->toBe([
        'vendor/first',
        'vendor/second',
    ])->and($input->lockedKind)->toBe('theme')
        ->and($input->includeLocalExtensionState)->toBeFalse()
        ->and($input->canManageExtensions)->toBeTrue();
});

it('exports the exact 17-key Marketplace selection computed property contract', function (): void {
    $explicitRecord = new MarketplaceSelectionRecordData(
        payload: [
            'composer_name' => 'vendor/selected',
            'name' => 'Selected',
        ],
        composerName: 'vendor/selected',
        name: 'Selected',
        requiredDependencies: ['vendor/dependency'],
        priceCents: 2500,
        requiresPremiumFlow: true,
        maturity: 'stable',
        failureReasonCode: null,
    );
    $dependencyRecord = new MarketplaceSelectionRecordData(
        payload: [
            'composer_name' => 'vendor/dependency',
            'name' => 'Dependency',
        ],
        composerName: 'vendor/dependency',
        name: '',
        requiredDependencies: [],
        priceCents: 0,
        requiresPremiumFlow: false,
        maturity: 'beta',
        failureReasonCode: null,
    );
    $review = new MarketplaceSelectionReviewData(
        explicitRecords: [$explicitRecord],
        dependencyRecords: [$dependencyRecord],
        installRecords: [$explicitRecord, $dependencyRecord],
        installComposerNames: ['vendor/selected', 'vendor/dependency'],
        dependencyComposerNames: ['vendor/dependency'],
        missingDependencies: ['vendor/missing'],
        blockedDependencies: [
            new MarketplaceSelectionBlockedDependencyData(
                name: 'Blocked',
                composerName: 'vendor/blocked',
                failureReasonCode: MarketplaceSelectionRecordData::FAILURE_INCOMPATIBLE,
            ),
        ],
        premiumRecords: [$explicitRecord],
        selectedCount: 1,
        installCount: 2,
        totalCents: 2500,
        hasPremiumRecords: true,
        containsBeta: true,
        betaDependencyComposerNames: ['vendor/dependency'],
        impactRecords: [
            $explicitRecord->toImpactArray(['vendor/selected' => true]),
            $dependencyRecord->toImpactArray(['vendor/selected' => true]),
        ],
        canInstall: false,
    );

    $export = $review->toComputedArray(
        freeTotalLabel: 'Free',
        unknownExtensionLabel: 'Unknown extension',
        failureReasonLabel: static fn (string $code): string => 'failure:' . $code,
        impactReasonLabel: static fn (string $code): string => 'impact:' . $code,
    );

    expect(array_keys($export))->toBe([
        'explicit_records',
        'dependency_records',
        'install_records',
        'install_composer_names',
        'dependency_composer_names',
        'missing_dependencies',
        'blocked_dependencies',
        'premium_records',
        'selected_count',
        'install_count',
        'total_cents',
        'total_label',
        'has_premium_records',
        'contains_beta',
        'beta_dependency_composer_names',
        'impact_records',
        'can_install',
    ])->and($export['blocked_dependencies'])->toBe([[
        'name' => 'Blocked',
        'composer_name' => 'vendor/blocked',
        'reason' => 'failure:incompatible',
    ]])->and($export['impact_records'][0]['reason'])->toBe('impact:direct')
        ->and($export['impact_records'][1]['reason'])->toBe('impact:dependency')
        ->and($export['impact_records'][1]['name'])->toBe('Unknown extension')
        ->and($export['total_label'])->toBe('$25.00');
});

it('uses the UI-provided free label without translating inside selection Data', function (): void {
    $review = new MarketplaceSelectionReviewData(
        explicitRecords: [],
        dependencyRecords: [],
        installRecords: [],
        installComposerNames: [],
        dependencyComposerNames: [],
        missingDependencies: [],
        blockedDependencies: [],
        premiumRecords: [],
        selectedCount: 0,
        installCount: 0,
        totalCents: 0,
        hasPremiumRecords: false,
        containsBeta: false,
        betaDependencyComposerNames: [],
        impactRecords: [],
        canInstall: false,
    );

    expect($review->toComputedArray(
        freeTotalLabel: 'Included',
        unknownExtensionLabel: 'Unknown extension',
        failureReasonLabel: static fn (string $code): string => $code,
        impactReasonLabel: static fn (string $code): string => $code,
    )['total_label'])->toBe('Included');
});

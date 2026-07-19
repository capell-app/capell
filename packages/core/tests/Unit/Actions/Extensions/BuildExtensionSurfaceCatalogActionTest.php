<?php

declare(strict_types=1);

use Capell\Admin\Contracts\AdminTools\AdminToolItem;
use Capell\Core\Actions\Extensions\BuildExtensionSurfaceCatalogAction;
use Capell\Core\Contracts\FrontendRouteReservationContributor;
use Capell\Core\Contracts\InteractionTargetCapabilityContributor;
use Capell\Core\Data\Extensions\ExtensionSurfaceCatalogEntryData;
use Capell\Core\Data\FrontendRouteReservationData;
use Capell\Core\Enums\Extensions\ExtensionSurfaceStability;
use Capell\Core\Enums\FrontendRouteReservationType;
use Capell\Frontend\Data\Assets\FrontendPackageDependencyData;
use Capell\Frontend\Enums\FrontendPackageDependencyType;
use Capell\Frontend\Support\Assets\FrontendPackageDependencyRegistry;
use Capell\Marketplace\Contracts\MarketplaceComposerChangePublisher;
use Capell\Marketplace\Data\MarketplaceComposerPublicationRequestData;
use Capell\Marketplace\Data\MarketplaceComposerPublicationResultData;

it('catalogues every supported extension surface kind from explicit metadata', function (): void {
    $catalog = BuildExtensionSurfaceCatalogAction::run();

    expect(array_column($catalog, 'kind'))->toContain(
        'contract',
        'action',
        'facade',
        'dto',
        'enum',
        'event',
        'tagged-service',
        'config',
        'render-hook',
        'registry',
        'testing',
        'internal',
    )
        ->and(array_column($catalog, 'id'))->toContain(
            'core.contract.site-spec-applier',
            'core.contract.project-build-artifact-handler',
            'core.action.project-build-signing-input',
            'core.action.validate-project-build-bundle',
            'core.action.verify-project-build-signature',
            'core.dto.project-build-manifest',
            'core.schema.project-build-manifest-v1',
            'core.tag.project-build-artifact-handler',
            'core.tag.site-spec-applier',
        );

    foreach ($catalog as $entry) {
        expect($entry->id)->not->toBe('')
            ->and($entry->ownerPackage)->toStartWith('capell-app/')
            ->and($entry->stability)->toBeInstanceOf(ExtensionSurfaceStability::class)
            ->and($entry->introducedVersion)->toMatch('/^\d+\.\d+\.\d+$/')
            ->and($entry->summary)->not->toBe('');

        if ($entry->stability === ExtensionSurfaceStability::Stable) {
            expect($entry->contractTestId)->not->toBeNull();
        }
    }
});

it('classifies the admin tool seam as experimental', function (): void {
    $catalog = collect(BuildExtensionSurfaceCatalogAction::run())->keyBy('id');
    $surfaceIds = [
        'admin.contract.admin-tool-item',
        'admin.tag.admin-tool-item',
    ];

    expect($catalog)->toHaveKeys($surfaceIds)
        ->and($catalog->get('admin.contract.admin-tool-item')?->identifier)->toBe(AdminToolItem::class)
        ->and($catalog->get('admin.tag.admin-tool-item')?->identifier)->toBe('capell-admin:admin-tool-items');

    foreach ($catalog->only($surfaceIds) as $entry) {
        expect($entry->ownerPackage)->toBe('capell-app/admin')
            ->and($entry->stability)->toBe(ExtensionSurfaceStability::Experimental);
    }
});

it('classifies the frontend package dependency seam as experimental', function (): void {
    $catalog = collect(BuildExtensionSurfaceCatalogAction::run())->keyBy('id');
    $surfaceIds = [
        'frontend.dto.package-dependency',
        'frontend.enum.package-dependency-type',
        'frontend.registry.package-dependency',
    ];

    expect($catalog)->toHaveKeys($surfaceIds)
        ->and($catalog->get('frontend.dto.package-dependency')?->identifier)->toBe(FrontendPackageDependencyData::class)
        ->and($catalog->get('frontend.enum.package-dependency-type')?->identifier)->toBe(FrontendPackageDependencyType::class)
        ->and($catalog->get('frontend.registry.package-dependency')?->identifier)->toBe(FrontendPackageDependencyRegistry::class);

    foreach ($catalog->only($surfaceIds) as $entry) {
        expect($entry->ownerPackage)->toBe('capell-app/frontend')
            ->and($entry->stability)->toBe(ExtensionSurfaceStability::Experimental);
    }
});

it('classifies the route reservation and interaction capability seams as experimental', function (): void {
    $catalog = collect(BuildExtensionSurfaceCatalogAction::run())->keyBy('id');

    expect($catalog)->toHaveKeys([
        'core.contract.frontend-route-reservation-contributor',
        'core.dto.frontend-route-reservation',
        'core.enum.frontend-route-reservation-type',
        'core.tag.frontend-route-reservation-contributor',
        'core.contract.interaction-target-capability-contributor',
        'core.tag.interaction-target-capability-contributor',
    ])
        ->and($catalog->get('core.contract.frontend-route-reservation-contributor')?->identifier)->toBe(FrontendRouteReservationContributor::class)
        ->and($catalog->get('core.dto.frontend-route-reservation')?->identifier)->toBe(FrontendRouteReservationData::class)
        ->and($catalog->get('core.enum.frontend-route-reservation-type')?->identifier)->toBe(FrontendRouteReservationType::class)
        ->and($catalog->get('core.tag.frontend-route-reservation-contributor')?->identifier)->toBe(FrontendRouteReservationContributor::TAG)
        ->and($catalog->get('core.contract.interaction-target-capability-contributor')?->identifier)->toBe(InteractionTargetCapabilityContributor::class)
        ->and($catalog->get('core.tag.interaction-target-capability-contributor')?->identifier)->toBe(InteractionTargetCapabilityContributor::TAG);

    foreach ($catalog->only([
        'core.contract.frontend-route-reservation-contributor',
        'core.dto.frontend-route-reservation',
        'core.enum.frontend-route-reservation-type',
        'core.tag.frontend-route-reservation-contributor',
        'core.contract.interaction-target-capability-contributor',
        'core.tag.interaction-target-capability-contributor',
    ]) as $entry) {
        expect($entry->stability)->toBe(ExtensionSurfaceStability::Experimental);
    }
});

it('classifies the marketplace composer publication seam as experimental', function (): void {
    $catalog = collect(BuildExtensionSurfaceCatalogAction::run())->keyBy('id');
    $surfaceIds = [
        'marketplace.contract.composer-change-publisher',
        'marketplace.dto.composer-publication-request',
        'marketplace.dto.composer-publication-result',
        'marketplace.tag.composer-change-publisher',
    ];

    expect($catalog)->toHaveKeys($surfaceIds)
        ->and($catalog->get('marketplace.contract.composer-change-publisher')?->identifier)->toBe(MarketplaceComposerChangePublisher::class)
        ->and($catalog->get('marketplace.dto.composer-publication-request')?->identifier)->toBe(MarketplaceComposerPublicationRequestData::class)
        ->and($catalog->get('marketplace.dto.composer-publication-result')?->identifier)->toBe(MarketplaceComposerPublicationResultData::class)
        ->and($catalog->get('marketplace.tag.composer-change-publisher')?->identifier)->toBe('capell.marketplace.composer-change-publisher');

    foreach ($catalog->only($surfaceIds) as $entry) {
        expect($entry->ownerPackage)->toBe('capell-app/marketplace')
            ->and($entry->stability)->toBe(ExtensionSurfaceStability::Experimental);
    }
});

it('rejects duplicate stable IDs', function (): void {
    $entry = new ExtensionSurfaceCatalogEntryData(
        id: 'core.contract.extension-contribution',
        kind: 'contract',
        identifier: 'Duplicate',
        ownerPackage: 'capell-app/core',
        stability: ExtensionSurfaceStability::Experimental,
        introducedVersion: '1.0.0',
        summary: 'Duplicate fixture.',
    );

    expect(fn (): array => BuildExtensionSurfaceCatalogAction::run([$entry]))
        ->toThrow(InvalidArgumentException::class, 'Duplicate extension surface ID');
});

it('rejects missing ownership metadata', function (): void {
    $entry = new ExtensionSurfaceCatalogEntryData(
        id: 'fixture.missing-owner',
        kind: 'contract',
        identifier: 'Fixture',
        ownerPackage: '',
        stability: ExtensionSurfaceStability::Experimental,
        introducedVersion: '1.0.0',
        summary: 'Fixture.',
    );

    expect(fn (): array => BuildExtensionSurfaceCatalogAction::run([$entry]))
        ->toThrow(InvalidArgumentException::class, 'require an ID, owner, and summary');
});

it('rejects stable surfaces without a direct contract test', function (): void {
    $entry = new ExtensionSurfaceCatalogEntryData(
        id: 'fixture.stable-without-test',
        kind: 'contract',
        identifier: 'Fixture',
        ownerPackage: 'capell-app/core',
        stability: ExtensionSurfaceStability::Stable,
        introducedVersion: '1.0.0',
        summary: 'Fixture.',
    );

    expect(fn (): array => BuildExtensionSurfaceCatalogAction::run([$entry]))
        ->toThrow(InvalidArgumentException::class, 'requires a contract test ID');
});

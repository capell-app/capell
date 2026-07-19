<?php

declare(strict_types=1);

use Capell\Admin\Actions\Tokens\IssueApiTokenAction;
use Capell\Admin\Contracts\AdminTools\AdminToolItem;
use Capell\Core\Actions\Extensions\BuildExtensionSurfaceCatalogAction;
use Capell\Core\Console\Commands\ExportBlueprintBlockSchemaCommand;
use Capell\Core\Contracts\FrontendRouteReservationContributor;
use Capell\Core\Contracts\InteractionTargetCapabilityContributor;
use Capell\Core\Data\Extensions\ExtensionSurfaceCatalogEntryData;
use Capell\Core\Data\FrontendRouteReservationData;
use Capell\Core\Enums\ApiTokenAbility;
use Capell\Core\Enums\Extensions\ExtensionSurfaceStability;
use Capell\Core\Enums\FrontendRouteReservationType;
use Capell\Core\Support\BlueprintBlockSchema;
use Capell\Frontend\Actions\BuildPageSchemaGraphAction;
use Capell\Frontend\Actions\ResolvePageSocialMetaAction;
use Capell\Frontend\Contracts\AeoRouteProvider;
use Capell\Frontend\Contracts\PageVariantNegotiator;
use Capell\Frontend\Contracts\RobotsDirectiveContributor;
use Capell\Frontend\Contracts\SchemaGraphContributor;
use Capell\Frontend\Data\Assets\FrontendPackageDependencyData;
use Capell\Frontend\Data\RobotsDirectiveData;
use Capell\Frontend\Data\SocialMetaData;
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

it('classifies scoped API token issuance as a stable extension surface', function (): void {
    $catalog = collect(BuildExtensionSurfaceCatalogAction::run())->keyBy('id');

    expect($catalog)->toHaveKeys([
        'admin.action.issue-api-token',
        'core.enum.api-token-ability',
    ])
        ->and($catalog->get('admin.action.issue-api-token')?->identifier)->toBe(IssueApiTokenAction::class)
        ->and($catalog->get('admin.action.issue-api-token')?->ownerPackage)->toBe('capell-app/admin')
        ->and($catalog->get('admin.action.issue-api-token')?->stability)->toBe(ExtensionSurfaceStability::Stable)
        ->and($catalog->get('admin.action.issue-api-token')?->contractTestId)->toBe('admin.issue-api-token')
        ->and($catalog->get('core.enum.api-token-ability')?->identifier)->toBe(ApiTokenAbility::class)
        ->and($catalog->get('core.enum.api-token-ability')?->stability)->toBe(ExtensionSurfaceStability::Stable)
        ->and($catalog->get('core.enum.api-token-ability')?->contractTestId)->toBe('core.api-token-ability');
});

it('classifies blueprint block schema export as a stable extension surface', function (): void {
    $catalog = collect(BuildExtensionSurfaceCatalogAction::run())->keyBy('id');

    expect($catalog->get('core.schema.blueprint-block-payload')?->identifier)->toBe(BlueprintBlockSchema::class)
        ->and($catalog->get('core.schema.blueprint-block-payload')?->stability)->toBe(ExtensionSurfaceStability::Stable)
        ->and($catalog->get('core.command.export-blueprint-block-schema')?->identifier)->toBe(ExportBlueprintBlockSchemaCommand::class)
        ->and($catalog->get('core.command.export-blueprint-block-schema')?->stability)->toBe(ExtensionSurfaceStability::Stable);
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

it('classifies the aeo response seams as stable extension contracts', function (): void {
    $catalog = collect(BuildExtensionSurfaceCatalogAction::run())->keyBy('id');
    $surfaceIds = [
        'frontend.contract.aeo-route-provider',
        'frontend.contract.page-variant-negotiator',
        'frontend.tag.aeo-route-provider',
        'frontend.tag.page-variant-negotiator',
    ];

    expect($catalog)->toHaveKeys($surfaceIds)
        ->and($catalog->get('frontend.contract.aeo-route-provider')?->identifier)->toBe(AeoRouteProvider::class)
        ->and($catalog->get('frontend.contract.page-variant-negotiator')?->identifier)->toBe(PageVariantNegotiator::class)
        ->and($catalog->get('frontend.tag.aeo-route-provider')?->identifier)->toBe(AeoRouteProvider::TAG)
        ->and($catalog->get('frontend.tag.page-variant-negotiator')?->identifier)->toBe(PageVariantNegotiator::TAG);

    foreach ($catalog->only($surfaceIds) as $entry) {
        expect($entry->ownerPackage)->toBe('capell-app/frontend')
            ->and($entry->stability)->toBe(ExtensionSurfaceStability::Stable)
            ->and($entry->contractTestId)->not->toBeNull();
    }
});

it('classifies schema graph contribution as a stable extension contract', function (): void {
    $catalog = collect(BuildExtensionSurfaceCatalogAction::run())->keyBy('id');
    $surfaceIds = [
        'frontend.action.build-page-schema-graph',
        'frontend.contract.schema-graph-contributor',
        'frontend.tag.schema-graph-contributor',
    ];

    expect($catalog)->toHaveKeys($surfaceIds)
        ->and($catalog->get('frontend.action.build-page-schema-graph')?->identifier)->toBe(BuildPageSchemaGraphAction::class)
        ->and($catalog->get('frontend.contract.schema-graph-contributor')?->identifier)->toBe(SchemaGraphContributor::class)
        ->and($catalog->get('frontend.tag.schema-graph-contributor')?->identifier)->toBe(SchemaGraphContributor::TAG);

    foreach ($catalog->only($surfaceIds) as $entry) {
        expect($entry->ownerPackage)->toBe('capell-app/frontend')
            ->and($entry->stability)->toBe(ExtensionSurfaceStability::Stable)
            ->and($entry->contractTestId)->not->toBeNull();
    }
});

it('classifies robots directive contribution as a stable extension contract', function (): void {
    $catalog = collect(BuildExtensionSurfaceCatalogAction::run())->keyBy('id');
    $surfaceIds = [
        'frontend.contract.robots-directive-contributor',
        'frontend.dto.robots-directive',
        'frontend.tag.robots-directive-contributor',
    ];

    expect($catalog)->toHaveKeys($surfaceIds)
        ->and($catalog->get('frontend.contract.robots-directive-contributor')?->identifier)->toBe(RobotsDirectiveContributor::class)
        ->and($catalog->get('frontend.dto.robots-directive')?->identifier)->toBe(RobotsDirectiveData::class)
        ->and($catalog->get('frontend.tag.robots-directive-contributor')?->identifier)->toBe(RobotsDirectiveContributor::TAG);

    foreach ($catalog->only($surfaceIds) as $entry) {
        expect($entry->ownerPackage)->toBe('capell-app/frontend')
            ->and($entry->stability)->toBe(ExtensionSurfaceStability::Stable)
            ->and($entry->contractTestId)->not->toBeNull();
    }
});

it('classifies public social metadata as a stable extension contract', function (): void {
    $catalog = collect(BuildExtensionSurfaceCatalogAction::run())->keyBy('id');

    expect($catalog)->toHaveKeys([
        'frontend.action.resolve-page-social-meta',
        'frontend.dto.social-meta',
    ])
        ->and($catalog->get('frontend.action.resolve-page-social-meta')?->identifier)->toBe(ResolvePageSocialMetaAction::class)
        ->and($catalog->get('frontend.dto.social-meta')?->identifier)->toBe(SocialMetaData::class)
        ->and($catalog->get('frontend.action.resolve-page-social-meta')?->stability)->toBe(ExtensionSurfaceStability::Stable)
        ->and($catalog->get('frontend.dto.social-meta')?->stability)->toBe(ExtensionSurfaceStability::Stable);
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

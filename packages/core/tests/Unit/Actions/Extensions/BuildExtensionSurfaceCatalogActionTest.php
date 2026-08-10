<?php

declare(strict_types=1);

use Capell\Admin\Contracts\AdminTools\AdminToolItem;
use Capell\Core\Actions\Extensions\BuildExtensionSurfaceCatalogAction;
use Capell\Core\Actions\ProjectBuild\CanonicalizeProjectBuildManifestSigningInputAction;
use Capell\Core\Actions\ProjectBuild\InstallProjectBuildManifestAction;
use Capell\Core\Actions\ProjectBuild\ValidateProjectBuildManifestBundleAction;
use Capell\Core\Actions\ProjectBuild\VerifyProjectBuildManifestSignatureAction;
use Capell\Core\Actions\ProjectBuild\VerifyProjectBuildTargetCompatibilityAction;
use Capell\Core\Actions\Publishing\BuildPublicationLocaleStatusAction;
use Capell\Core\Contracts\Database\DatabasePlatform;
use Capell\Core\Contracts\Database\DatabaseProvisioner;
use Capell\Core\Contracts\Database\DatabaseQueryDialect;
use Capell\Core\Contracts\Database\DatabaseSchemaDialect;
use Capell\Core\Contracts\FrontendRouteReservationContributor;
use Capell\Core\Contracts\InteractionTargetCapabilityContributor;
use Capell\Core\Contracts\ProjectBuild\ProjectBuildPackageInstaller;
use Capell\Core\Data\Database\DatabaseIndexDefinition;
use Capell\Core\Data\Database\SqlFragment;
use Capell\Core\Data\Extensions\ExtensionSurfaceCatalogEntryData;
use Capell\Core\Data\FrontendRouteReservationData;
use Capell\Core\Data\ProjectBuild\ProjectBuildArtifactReferenceData;
use Capell\Core\Data\ProjectBuild\ProjectBuildCompatibilityData;
use Capell\Core\Data\ProjectBuild\ProjectBuildInstalledPackageData;
use Capell\Core\Data\ProjectBuild\ProjectBuildManifestData;
use Capell\Core\Data\ProjectBuild\ProjectBuildPackageData;
use Capell\Core\Data\ProjectBuild\ProjectBuildRouteData;
use Capell\Core\Data\ProjectBuild\ProjectBuildSignatureData;
use Capell\Core\Data\ProjectBuild\ProjectBuildSiteData;
use Capell\Core\Data\ProjectBuild\ProjectBuildSiteSpecReferenceData;
use Capell\Core\Data\Publishing\PublicationLocaleStatusContextData;
use Capell\Core\Data\Publishing\PublicationLocaleStatusData;
use Capell\Core\Enums\Database\DatabaseCapability;
use Capell\Core\Enums\Database\DatabaseDateOperation;
use Capell\Core\Enums\Database\DatabaseFamily;
use Capell\Core\Enums\Database\DatabaseProvisioningResult;
use Capell\Core\Enums\Extensions\ExtensionSurfaceStability;
use Capell\Core\Enums\FrontendRouteReservationType;
use Capell\Core\Facades\CapellDatabase;
use Capell\Core\Support\Database\DatabasePlatformRegistry;
use Capell\Core\Support\ProjectBuild\ProjectBuildArtifactHandlerRegistry;
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
            'core.contract.project-build-package-installer',
            'core.action.install-project-build-manifest',
            'core.action.verify-project-build-target',
            'core.action.project-build-signing-input',
            'core.action.validate-project-build-bundle',
            'core.action.verify-project-build-signature',
            'core.dto.project-build-manifest',
            'core.schema.project-build-manifest-v1',
            'core.tag.project-build-artifact-handler',
            'core.tag.site-spec-applier',
            'core.contract.publication-readiness-contributor',
            'core.dto.publication-readiness-check',
            'core.dto.publication-readiness-context',
            'core.tag.publication-readiness-contributor',
            'core.registry.publication-readiness',
            'core.action.build-publication-locale-status',
            'core.dto.publication-locale-status-context',
            'core.dto.publication-locale-status',
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

it('keeps the project build producer actions on their approved stable contracts', function (): void {
    $catalog = collect(BuildExtensionSurfaceCatalogAction::run())->keyBy('id');

    expect($catalog->get('core.action.project-build-signing-input')?->identifier)
        ->toBe(CanonicalizeProjectBuildManifestSigningInputAction::class)
        ->and($catalog->get('core.action.project-build-signing-input')?->stability)
        ->toBe(ExtensionSurfaceStability::Stable)
        ->and($catalog->get('core.action.project-build-signing-input')?->contractTestId)
        ->toBe('core.project-build-manifest-signing')
        ->and($catalog->get('core.action.verify-project-build-signature')?->identifier)
        ->toBe(VerifyProjectBuildManifestSignatureAction::class)
        ->and($catalog->get('core.action.verify-project-build-signature')?->stability)
        ->toBe(ExtensionSurfaceStability::Stable)
        ->and($catalog->get('core.action.verify-project-build-signature')?->contractTestId)
        ->toBe('core.project-build-manifest-signing')
        ->and($catalog->get('core.action.validate-project-build-bundle')?->identifier)
        ->toBe(ValidateProjectBuildManifestBundleAction::class)
        ->and($catalog->get('core.action.validate-project-build-bundle')?->stability)
        ->toBe(ExtensionSurfaceStability::Stable)
        ->and($catalog->get('core.action.validate-project-build-bundle')?->contractTestId)
        ->toBe('core.project-build-manifest-bundle')
        ->and($catalog->get('core.schema.project-build-manifest-v1')?->stability)
        ->toBe(ExtensionSurfaceStability::Experimental)
        ->and($catalog->get('core.contract.project-build-artifact-handler')?->stability)
        ->toBe(ExtensionSurfaceStability::Stable)
        ->and($catalog->get('core.action.install-project-build-manifest')?->identifier)
        ->toBe(InstallProjectBuildManifestAction::class)
        ->and($catalog->get('core.contract.project-build-package-installer')?->identifier)
        ->toBe(ProjectBuildPackageInstaller::class)
        ->and($catalog->get('core.action.verify-project-build-target')?->identifier)
        ->toBe(VerifyProjectBuildTargetCompatibilityAction::class);
});

it('closes the stable project build action signatures over typed data and registry surfaces', function (): void {
    $catalog = collect(BuildExtensionSurfaceCatalogAction::run())->keyBy('id');
    $closure = [
        'core.dto.project-build-artifact-reference' => ProjectBuildArtifactReferenceData::class,
        'core.dto.project-build-compatibility' => ProjectBuildCompatibilityData::class,
        'core.dto.project-build-installed-package' => ProjectBuildInstalledPackageData::class,
        'core.dto.project-build-manifest' => ProjectBuildManifestData::class,
        'core.dto.project-build-package' => ProjectBuildPackageData::class,
        'core.dto.project-build-route' => ProjectBuildRouteData::class,
        'core.dto.project-build-signature' => ProjectBuildSignatureData::class,
        'core.dto.project-build-site' => ProjectBuildSiteData::class,
        'core.dto.project-build-site-spec-reference' => ProjectBuildSiteSpecReferenceData::class,
        'core.registry.project-build-artifact-handler' => ProjectBuildArtifactHandlerRegistry::class,
    ];

    expect($catalog)->toHaveKeys(array_keys($closure));

    foreach ($closure as $id => $identifier) {
        expect($catalog->get($id)?->identifier)->toBe($identifier)
            ->and($catalog->get($id)?->stability)->toBe(ExtensionSurfaceStability::Stable)
            ->and($catalog->get($id)?->contractTestId)->not->toBeNull();
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

it('classifies the database compatibility seam as experimental', function (): void {
    $catalog = collect(BuildExtensionSurfaceCatalogAction::run())->keyBy('id');
    $surfaces = [
        'core.contract.database-platform' => DatabasePlatform::class,
        'core.contract.database-provisioner' => DatabaseProvisioner::class,
        'core.contract.database-query-dialect' => DatabaseQueryDialect::class,
        'core.contract.database-schema-dialect' => DatabaseSchemaDialect::class,
        'core.dto.database-index-definition' => DatabaseIndexDefinition::class,
        'core.dto.sql-fragment' => SqlFragment::class,
        'core.enum.database-capability' => DatabaseCapability::class,
        'core.enum.database-date-operation' => DatabaseDateOperation::class,
        'core.enum.database-family' => DatabaseFamily::class,
        'core.enum.database-provisioning-result' => DatabaseProvisioningResult::class,
        'core.facade.capell-database' => CapellDatabase::class,
        'core.registry.database-platform' => DatabasePlatformRegistry::class,
        'core.tag.database-platform' => DatabasePlatform::TAG,
    ];

    expect($catalog)->toHaveKeys(array_keys($surfaces));

    foreach ($surfaces as $id => $identifier) {
        expect($catalog->get($id)?->identifier)->toBe($identifier)
            ->and($catalog->get($id)?->stability)->toBe(ExtensionSurfaceStability::Experimental);
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

it('classifies the complete consumer metrics contract closure as experimental', function (): void {
    $catalog = collect(BuildExtensionSurfaceCatalogAction::run())->keyBy('id');
    $surfaceIds = [
        'core.contract.collects-daily-metrics',
        'core.contract.metric-scope-authorizer',
        'core.dto.metric-collection-result',
        'core.dto.metric-definition',
        'core.dto.metric-governance',
        'core.dto.metric-identity',
        'core.dto.metric-read-context',
        'core.dto.metric-representation',
        'core.dto.metric-sample',
        'core.dto.metric-scope',
        'core.dto.metric-semantics',
        'core.dto.metric-value',
        'core.enum.metric-aggregation',
        'core.enum.metric-backfill-policy',
        'core.enum.metric-collection-status',
        'core.enum.metric-definition-status',
        'core.enum.metric-gap-policy',
        'core.enum.metric-reader-type',
        'core.enum.metric-scope-type',
        'core.enum.metric-semantic',
        'core.enum.metric-sensitivity',
        'core.enum.metric-source',
        'core.enum.metric-unit',
        'core.enum.metric-value-type',
        'core.enum.metric-visibility',
    ];

    expect($catalog)->toHaveKeys($surfaceIds);

    foreach ($catalog->only($surfaceIds) as $entry) {
        expect($entry->ownerPackage)->toBe('capell-app/core')
            ->and($entry->stability)->toBe(ExtensionSurfaceStability::Experimental)
            ->and($entry->contractTestId)->toBeNull();
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

it('catalogues the locale publication status projection as experimental', function (): void {
    $catalog = collect(BuildExtensionSurfaceCatalogAction::run())->keyBy('id');

    expect($catalog->get('core.action.build-publication-locale-status')?->identifier)
        ->toBe(BuildPublicationLocaleStatusAction::class)
        ->and($catalog->get('core.dto.publication-locale-status-context')?->identifier)
        ->toBe(PublicationLocaleStatusContextData::class)
        ->and($catalog->get('core.dto.publication-locale-status')?->identifier)
        ->toBe(PublicationLocaleStatusData::class)
        ->and($catalog->get('core.action.build-publication-locale-status')?->stability)
        ->toBe(ExtensionSurfaceStability::Experimental);
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

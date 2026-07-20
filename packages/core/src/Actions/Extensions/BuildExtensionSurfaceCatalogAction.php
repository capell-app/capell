<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Extensions;

use Capell\Core\Actions\ProjectBuild\CanonicalizeProjectBuildManifestSigningInputAction;
use Capell\Core\Actions\ProjectBuild\ValidateProjectBuildManifestBundleAction;
use Capell\Core\Actions\ProjectBuild\VerifyProjectBuildManifestSignatureAction;
use Capell\Core\Contracts\Extensions\ChecksExtensionHealth;
use Capell\Core\Contracts\Extensions\ExtensionContribution;
use Capell\Core\Contracts\FrontendRouteReservationContributor;
use Capell\Core\Contracts\InteractionTargetCapabilityContributor;
use Capell\Core\Contracts\ProjectBuild\ProjectBuildArtifactHandler;
use Capell\Core\Contracts\ProjectBuild\ProjectBuildManifestMigration;
use Capell\Core\Contracts\SiteSpec\SiteSpecApplier;
use Capell\Core\Data\Extensions\ExtensionSurfaceCatalogEntryData;
use Capell\Core\Data\FrontendRouteReservationData;
use Capell\Core\Data\Manifest\ExtensionContributionData;
use Capell\Core\Data\ProjectBuild\ProjectBuildManifestData;
use Capell\Core\Enums\Extensions\ExtensionSurfaceStability;
use Capell\Core\Enums\FrontendRouteReservationType;
use Capell\Core\Events\PackageInstalled;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\ProjectBuild\ProjectBuildManifestSchema;
use Capell\Core\Testing\ExtensionTestHarness;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class BuildExtensionSurfaceCatalogAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  list<ExtensionSurfaceCatalogEntryData>  $additionalEntries
     * @return list<ExtensionSurfaceCatalogEntryData>
     */
    public function handle(array $additionalEntries = []): array
    {
        $entries = [...$this->foundationEntries(), ...$additionalEntries];
        $indexed = [];

        foreach ($entries as $entry) {
            throw_if($entry->id === '' || $entry->ownerPackage === '' || $entry->summary === '', InvalidArgumentException::class, 'Extension surface entries require an ID, owner, and summary.');

            if (isset($indexed[$entry->id])) {
                throw new InvalidArgumentException(sprintf('Duplicate extension surface ID [%s].', $entry->id));
            }

            if ($entry->stability === ExtensionSurfaceStability::Stable && $entry->contractTestId === null) {
                throw new InvalidArgumentException(sprintf('Stable extension surface [%s] requires a contract test ID.', $entry->id));
            }

            $indexed[$entry->id] = $entry;
        }

        uasort($indexed, static fn (ExtensionSurfaceCatalogEntryData $left, ExtensionSurfaceCatalogEntryData $right): int => [
            $left->ownerPackage, $left->kind, $left->id,
        ] <=> [$right->ownerPackage, $right->kind, $right->id]);

        return array_values($indexed);
    }

    /** @return list<ExtensionSurfaceCatalogEntryData> */
    private function foundationEntries(): array
    {
        return [
            $this->entry('core.contract.extension-contribution', 'contract', ExtensionContribution::class, ExtensionSurfaceStability::Stable, 'Core contribution boundary.', 'core.extension-contribution'),
            $this->entry('core.contract.frontend-route-reservation-contributor', 'contract', FrontendRouteReservationContributor::class, ExtensionSurfaceStability::Experimental, 'Typed frontend route reservation contributions.'),
            $this->entry('core.contract.health-check', 'contract', ChecksExtensionHealth::class, ExtensionSurfaceStability::Experimental, 'Typed extension health checks.'),
            $this->entry('core.contract.interaction-target-capability-contributor', 'contract', InteractionTargetCapabilityContributor::class, ExtensionSurfaceStability::Experimental, 'Typed interaction target capability contributions.'),
            $this->entry('core.contract.project-build-artifact-handler', 'contract', ProjectBuildArtifactHandler::class, ExtensionSurfaceStability::Stable, 'Package-owned project artifact verification boundary.', 'core.project-build-artifact-handler'),
            $this->entry('core.action.project-build-signing-input', 'action', CanonicalizeProjectBuildManifestSigningInputAction::class, ExtensionSurfaceStability::Stable, 'Canonical detached-signature input for portable project manifests.', 'core.project-build-manifest-signing'),
            $this->entry('core.action.validate-project-build-bundle', 'action', ValidateProjectBuildManifestBundleAction::class, ExtensionSurfaceStability::Stable, 'Fail-closed signature and artifact validation for portable project manifests.', 'core.project-build-manifest-bundle'),
            $this->entry('core.action.verify-project-build-signature', 'action', VerifyProjectBuildManifestSignatureAction::class, ExtensionSurfaceStability::Stable, 'Ed25519 verification for portable project manifests.', 'core.project-build-manifest-signing'),
            $this->entry('core.contract.site-spec-applier', 'contract', SiteSpecApplier::class, ExtensionSurfaceStability::Stable, 'Package-owned SiteSpec application boundary.', 'core.site-spec-applier'),
            $this->entry('core.facade.capell-core', 'facade', CapellCore::class, ExtensionSurfaceStability::Experimental, 'Runtime package and model registry facade.'),
            $this->entry('core.dto.extension-contribution', 'dto', ExtensionContributionData::class, ExtensionSurfaceStability::Stable, 'Typed manifest contribution data.', 'core.extension-contribution-data'),
            $this->entry('core.dto.frontend-route-reservation', 'dto', FrontendRouteReservationData::class, ExtensionSurfaceStability::Experimental, 'Typed frontend route reservation data.'),
            $this->entry('core.dto.project-build-manifest', 'dto', ProjectBuildManifestData::class, ExtensionSurfaceStability::Experimental, 'Typed portable project build manifest envelope.'),
            $this->entry('core.enum.frontend-route-reservation-type', 'enum', FrontendRouteReservationType::class, ExtensionSurfaceStability::Experimental, 'Supported frontend route reservation types.'),
            $this->entry('core.event.package-installed', 'event', PackageInstalled::class, ExtensionSurfaceStability::Stable, 'Package lifecycle completion event.', 'core.package-installed-event'),
            $this->entry('core.tag.extension-health', 'tagged-service', 'capell.extension-health-checks', ExtensionSurfaceStability::Experimental, 'Container tag for extension health checks.'),
            $this->entry('core.tag.frontend-route-reservation-contributor', 'tagged-service', FrontendRouteReservationContributor::TAG, ExtensionSurfaceStability::Experimental, 'Container tag for frontend route reservation contributors.'),
            $this->entry('core.tag.interaction-target-capability-contributor', 'tagged-service', InteractionTargetCapabilityContributor::TAG, ExtensionSurfaceStability::Experimental, 'Container tag for interaction target capability contributors.'),
            $this->entry('core.tag.project-build-artifact-handler', 'tagged-service', ProjectBuildArtifactHandler::TAG, ExtensionSurfaceStability::Stable, 'Container tag for project build artifact handlers.', 'core.project-build-artifact-handler-registration'),
            $this->entry('core.tag.site-spec-applier', 'tagged-service', SiteSpecApplier::TAG, ExtensionSurfaceStability::Stable, 'Container tag for SiteSpec appliers.', 'core.site-spec-applier-registration'),
            $this->entry('core.config.roles-admin', 'config', 'capell.roles.admin', ExtensionSurfaceStability::Experimental, 'Configured administrator role name.'),
            $this->entry('admin.contract.admin-tool-item', 'contract', 'Capell\\Admin\\Contracts\\AdminTools\\AdminToolItem', ExtensionSurfaceStability::Experimental, 'Typed admin header tool contribution boundary.', owner: 'capell-app/admin'),
            $this->entry('admin.render-hook.navigation-after', 'render-hook', 'panels::sidebar.nav.end', ExtensionSurfaceStability::Experimental, 'Admin navigation contribution hook.', owner: 'capell-app/admin'),
            $this->entry('admin.tag.admin-tool-item', 'tagged-service', 'capell-admin:admin-tool-items', ExtensionSurfaceStability::Experimental, 'Container tag for admin header tool contributions.', owner: 'capell-app/admin'),
            $this->entry('marketplace.contract.composer-change-publisher', 'contract', 'Capell\\Marketplace\\Contracts\\MarketplaceComposerChangePublisher', ExtensionSurfaceStability::Experimental, 'Typed optional Composer change publication boundary.', owner: 'capell-app/marketplace'),
            $this->entry('marketplace.dto.composer-publication-request', 'dto', 'Capell\\Marketplace\\Data\\MarketplaceComposerPublicationRequestData', ExtensionSurfaceStability::Experimental, 'Typed Composer publication request data.', owner: 'capell-app/marketplace'),
            $this->entry('marketplace.dto.composer-publication-result', 'dto', 'Capell\\Marketplace\\Data\\MarketplaceComposerPublicationResultData', ExtensionSurfaceStability::Experimental, 'Typed Composer publication result data.', owner: 'capell-app/marketplace'),
            $this->entry('marketplace.tag.composer-change-publisher', 'tagged-service', 'capell.marketplace.composer-change-publisher', ExtensionSurfaceStability::Experimental, 'Container tag for optional Composer change publishers.', owner: 'capell-app/marketplace'),
            $this->entry('core.testing.extension-harness', 'testing', ExtensionTestHarness::class, ExtensionSurfaceStability::Stable, 'Single-package manifest and contribution assertions.', 'core.extension-test-harness'),
            $this->entry('core.schema.project-build-manifest-v1', 'schema', ProjectBuildManifestSchema::class, ExtensionSurfaceStability::Experimental, 'Closed JSON Schema for portable project build manifests.'),
            $this->entry('core.internal.registry-builder', 'internal', BuildExtensionContractRegistryAction::class, ExtensionSurfaceStability::Internal, 'Internal executable contribution index.'),
            $this->entry('core.internal.project-build-manifest-migration', 'internal', ProjectBuildManifestMigration::class, ExtensionSurfaceStability::Internal, 'Core-owned portable manifest migration boundary.'),
            $this->entry('frontend.contract.component-contributor', 'contract', 'Capell\\Frontend\\Contracts\\FrontendComponentContributor', ExtensionSurfaceStability::Experimental, 'Typed frontend component contribution boundary.', owner: 'capell-app/frontend'),
            $this->entry('frontend.contract.widget-resource-usage-contributor', 'contract', 'Capell\\Frontend\\Contracts\\FrontendWidgetResourceUsageContributor', ExtensionSurfaceStability::Experimental, 'Typed widget resource usage contribution boundary.', owner: 'capell-app/frontend'),
            $this->entry('frontend.dto.component-contribution', 'dto', 'Capell\\Frontend\\Data\\FrontendComponentContributionData', ExtensionSurfaceStability::Experimental, 'Named component contribution for a frontend runtime target.', owner: 'capell-app/frontend'),
            $this->entry('frontend.dto.package-dependency', 'dto', 'Capell\\Frontend\\Data\\Assets\\FrontendPackageDependencyData', ExtensionSurfaceStability::Experimental, 'Typed frontend package dependency declaration.', owner: 'capell-app/frontend'),
            $this->entry('frontend.dto.widget-resource-usage', 'dto', 'Capell\\Frontend\\Data\\Assets\\FrontendWidgetResourceUsageData', ExtensionSurfaceStability::Experimental, 'Typed widget resource usage data.', owner: 'capell-app/frontend'),
            $this->entry('frontend.enum.component-target', 'enum', 'Capell\\Frontend\\Enums\\FrontendComponentTarget', ExtensionSurfaceStability::Experimental, 'Supported frontend component runtime targets.', owner: 'capell-app/frontend'),
            $this->entry('frontend.enum.package-dependency-type', 'enum', 'Capell\\Frontend\\Enums\\FrontendPackageDependencyType', ExtensionSurfaceStability::Experimental, 'Supported frontend package dependency installation types.', owner: 'capell-app/frontend'),
            $this->entry('frontend.registry.package-dependency', 'registry', 'Capell\\Frontend\\Support\\Assets\\FrontendPackageDependencyRegistry', ExtensionSurfaceStability::Experimental, 'Registry for frontend package dependency declarations.', owner: 'capell-app/frontend'),
            $this->entry('frontend.tag.component-contributor', 'tagged-service', 'capell.frontend.component-contributor', ExtensionSurfaceStability::Experimental, 'Container tag for frontend component contributors.', owner: 'capell-app/frontend'),
            $this->entry('frontend.tag.widget-resource-usage-contributor', 'tagged-service', 'capell.frontend.widget-resource-usage-contributor', ExtensionSurfaceStability::Experimental, 'Container tag for widget resource usage contributors.', owner: 'capell-app/frontend'),
        ];
    }

    private function entry(
        string $id,
        string $kind,
        string $identifier,
        ExtensionSurfaceStability $stability,
        string $summary,
        ?string $contractTestId = null,
        string $owner = 'capell-app/core',
    ): ExtensionSurfaceCatalogEntryData {
        return new ExtensionSurfaceCatalogEntryData(
            id: $id,
            kind: $kind,
            identifier: $identifier,
            ownerPackage: $owner,
            stability: $stability,
            introducedVersion: '1.0.0',
            summary: $summary,
            contractTestId: $contractTestId,
        );
    }
}

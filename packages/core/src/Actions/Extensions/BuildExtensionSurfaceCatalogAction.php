<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Extensions;

use Capell\Core\Contracts\Extensions\ChecksExtensionHealth;
use Capell\Core\Contracts\Extensions\ExtensionContribution;
use Capell\Core\Data\Extensions\ExtensionSurfaceCatalogEntryData;
use Capell\Core\Data\Manifest\ExtensionContributionData;
use Capell\Core\Enums\Extensions\ExtensionSurfaceStability;
use Capell\Core\Events\PackageInstalled;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Testing\ExtensionTestHarness;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsObject;

final class BuildExtensionSurfaceCatalogAction
{
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
            $this->entry('core.contract.health-check', 'contract', ChecksExtensionHealth::class, ExtensionSurfaceStability::Experimental, 'Typed extension health checks.'),
            $this->entry('core.facade.capell-core', 'facade', CapellCore::class, ExtensionSurfaceStability::Experimental, 'Runtime package and model registry facade.'),
            $this->entry('core.dto.extension-contribution', 'dto', ExtensionContributionData::class, ExtensionSurfaceStability::Stable, 'Typed manifest contribution data.', 'core.extension-contribution-data'),
            $this->entry('core.event.package-installed', 'event', PackageInstalled::class, ExtensionSurfaceStability::Stable, 'Package lifecycle completion event.', 'core.package-installed-event'),
            $this->entry('core.tag.extension-health', 'tagged-service', 'capell.extension-health-checks', ExtensionSurfaceStability::Experimental, 'Container tag for extension health checks.'),
            $this->entry('core.config.roles-admin', 'config', 'capell.roles.admin', ExtensionSurfaceStability::Experimental, 'Configured administrator role name.'),
            $this->entry('admin.render-hook.navigation-after', 'render-hook', 'panels::sidebar.nav.end', ExtensionSurfaceStability::Experimental, 'Admin navigation contribution hook.', owner: 'capell-app/admin'),
            $this->entry('core.testing.extension-harness', 'testing', ExtensionTestHarness::class, ExtensionSurfaceStability::Stable, 'Single-package manifest and contribution assertions.', 'core.extension-test-harness'),
            $this->entry('core.internal.registry-builder', 'internal', BuildExtensionContractRegistryAction::class, ExtensionSurfaceStability::Internal, 'Internal executable contribution index.'),
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

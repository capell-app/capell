<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Extensions;

use Capell\Core\Data\Extensions\ExtensionSurfaceCatalogEntryData;
use Capell\Core\Data\Manifest\ExtensionContributionData;
use Capell\Core\Enums\ExtensionContributionType;
use Capell\Core\Support\Manifest\CapellManifestData;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static array{byType: array<string, list<ExtensionContributionData>>, byPackage: array<string, list<ExtensionContributionData>>, bySurface: array<string, list<ExtensionContributionData>>, byClass: array<string, ExtensionContributionData>, surfaceCatalog: array<string, ExtensionSurfaceCatalogEntryData>} run(array<string, CapellManifestData> $manifests)
 */
final class BuildExtensionContractRegistryAction
{
    use AsObject;

    /**
     * @param  array<string, CapellManifestData>  $manifests
     * @return array{byType: array<string, list<ExtensionContributionData>>, byPackage: array<string, list<ExtensionContributionData>>, bySurface: array<string, list<ExtensionContributionData>>, byClass: array<string, ExtensionContributionData>, surfaceCatalog: array<string, ExtensionSurfaceCatalogEntryData>}
     */
    public function handle(array $manifests): array
    {
        $registry = [
            'byType' => [],
            'byPackage' => [],
            'bySurface' => [],
            'byClass' => [],
            'surfaceCatalog' => [],
        ];

        foreach (BuildExtensionSurfaceCatalogAction::run() as $entry) {
            $registry['surfaceCatalog'][$entry->id] = $entry;
        }

        foreach ($manifests as $packageName => $manifest) {
            foreach ($manifest->contributes as $contribution) {
                $registry['byType'][$contribution->type->value][] = $contribution;
                $registry['byPackage'][$packageName][] = $contribution;

                foreach ($this->surfacesForContribution($manifest, $contribution) as $surface) {
                    $registry['bySurface'][$surface][] = $contribution;
                }

                if ($contribution->class !== null) {
                    $registry['byClass'][$contribution->class] = $contribution;
                }
            }
        }

        return $registry;
    }

    /**
     * @return list<string>
     */
    private function surfacesForContribution(CapellManifestData $manifest, ExtensionContributionData $contribution): array
    {
        if ($contribution->type === ExtensionContributionType::ContentWidget) {
            return ['frontend'];
        }

        $surface = $contribution->metadata['surface'] ?? null;

        if (is_string($surface) && $surface !== '') {
            return [$surface];
        }

        return $manifest->surfaces;
    }
}

<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Frontend\Data\FrontendAssetManifestData;
use Capell\Frontend\Data\FrontendAssetRequirementData;
use Lorisleiva\Actions\Concerns\AsObject;

class DetectFrontendResourceConflictsAction
{
    use AsObject;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function handle(FrontendAssetManifestData $manifest): array
    {
        $assets = $manifest->rawRequirements !== []
            ? $manifest->rawRequirements
            : [...$manifest->css, ...$manifest->js, ...$manifest->lazy];

        return collect($assets)
            ->filter(fn (mixed $asset): bool => $asset instanceof FrontendAssetRequirementData)
            ->groupBy(fn (FrontendAssetRequirementData $asset): string => implode(':', [
                $asset->kind,
                $asset->buildPath ?? '',
                $asset->source,
            ]))
            ->filter(fn ($assets): bool => collect($assets)
                ->map(fn (FrontendAssetRequirementData $asset): string => implode(':', [
                    $asset->loadingStrategy->value,
                    $asset->defer ? 'defer' : 'nodefer',
                    $asset->async ? 'async' : 'noasync',
                    $asset->usesModuleScript() ? 'module' : 'nomodule',
                    $asset->condition ?? '',
                ]))
                ->unique()
                ->count() > 1)
            ->map(fn ($assets): array => $this->conflict($assets->values()->all()))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, FrontendAssetRequirementData>  $assets
     * @return array<string, mixed>
     */
    private function conflict(array $assets): array
    {
        $firstAsset = $assets[0];

        return [
            'source' => $firstAsset->source,
            'kind' => $firstAsset->kind,
            'buildPath' => $firstAsset->buildPath,
            'variants' => collect($assets)->map(fn (FrontendAssetRequirementData $asset): array => [
                'handle' => $asset->handle,
                'loadingStrategy' => $asset->loadingStrategy->value,
                'defer' => $asset->defer,
                'async' => $asset->async,
                'module' => $asset->usesModuleScript(),
                'condition' => $asset->condition,
            ])->values()->all(),
        ];
    }
}

<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Frontend\Data\FrontendAssetRequirementData;
use Capell\Frontend\Data\FrontendRenderContextData;
use Capell\Frontend\Data\PublicPageRenderData;
use Capell\Frontend\Data\PublicRenderPerformanceReportData;
use Capell\Frontend\Support\Cache\PublicPageRenderDataCache;
use Lorisleiva\Actions\Concerns\AsObject;

class BuildPublicRenderPerformanceReportAction
{
    use AsObject;

    public function handle(
        PublicPageRenderData $renderData,
        ?FrontendRenderContextData $context = null,
        ?int $lastRenderMilliseconds = null,
    ): PublicRenderPerformanceReportData {
        $assetManifest = $renderData->assetManifest;
        $runtime = $renderData->runtimeManifest;
        $sizes = MeasureFrontendAssetSizesAction::run($assetManifest);

        return new PublicRenderPerformanceReportData(
            renderingStrategy: $runtime->renderingStrategy->value,
            runtimeModules: [
                'livewire' => $runtime->usesLivewire,
                'alpine' => $runtime->usesAlpine,
                'beacon' => $runtime->usesBeacon,
                'wireNavigate' => $runtime->usesWireNavigate,
                'islands' => $runtime->usesIslands,
                'inertia' => $runtime->usesInertia,
            ],
            assetCounts: [
                'css' => count($assetManifest->css),
                'js' => count($assetManifest->js),
                'inline' => count($assetManifest->inline),
                'preloads' => count($assetManifest->preloads),
                'mediaPreloads' => count($renderData->mediaHints),
            ],
            byteCounts: [
                'inline' => $this->inlineBytes($assetManifest->inline),
                'js' => $this->assetBytes($assetManifest->js),
                'jsRaw' => $sizes->rawJsBytes,
                'jsGzip' => $sizes->gzipJsBytes,
                'css' => $this->assetBytes($assetManifest->css),
                'cssRaw' => $sizes->rawCssBytes,
                'cssGzip' => $sizes->gzipCssBytes,
                'criticalCss' => $this->criticalCssBytes($assetManifest->inline),
            ],
            surrogateKeys: $renderData->surrogateKeys,
            assetReasons: $this->assetReasons([
                ...$assetManifest->css,
                ...$assetManifest->js,
                ...$assetManifest->inline,
                ...$assetManifest->preloads,
            ]),
            renderDataCacheKey: $context instanceof FrontendRenderContextData
                ? resolve(PublicPageRenderDataCache::class)->keyForContext($context)
                : null,
            layoutGraphKey: $renderData->layoutGraphKey(),
            lastRenderMilliseconds: $lastRenderMilliseconds,
        );
    }

    /**
     * @param  array<int, FrontendAssetRequirementData>  $inlineAssets
     */
    private function inlineBytes(array $inlineAssets): int
    {
        return collect($inlineAssets)
            ->sum(fn (FrontendAssetRequirementData $asset): int => strlen($asset->source));
    }

    /**
     * @param  array<int, FrontendAssetRequirementData>  $assets
     */
    private function assetBytes(array $assets): int
    {
        return collect($assets)
            ->sum(fn (FrontendAssetRequirementData $asset): int => strlen($asset->source));
    }

    /**
     * @param  array<int, FrontendAssetRequirementData>  $inlineAssets
     */
    private function criticalCssBytes(array $inlineAssets): int
    {
        return collect($inlineAssets)
            ->filter(fn (FrontendAssetRequirementData $asset): bool => str_contains($asset->handle, 'critical') || str_contains($asset->source, '<style'))
            ->sum(fn (FrontendAssetRequirementData $asset): int => strlen($asset->source));
    }

    /**
     * @param  array<int, FrontendAssetRequirementData>  $assets
     * @return array<int, array<string, mixed>>
     */
    private function assetReasons(array $assets): array
    {
        return collect($assets)
            ->map(fn (FrontendAssetRequirementData $asset): array => [
                'handle' => $asset->handle,
                'kind' => $asset->kind,
                'source' => $asset->source,
                'buildPath' => $asset->buildPath,
                'condition' => $asset->condition,
            ])
            ->values()
            ->all();
    }
}

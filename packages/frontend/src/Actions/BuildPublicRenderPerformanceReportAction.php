<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Frontend\Data\Assets\ResolvedFrontendResourceData;
use Capell\Frontend\Data\FrontendRenderContextData;
use Capell\Frontend\Data\PublicPageRenderData;
use Capell\Frontend\Data\PublicRenderPerformanceReportData;
use Capell\Frontend\Enums\FrontendResourceKind;
use Capell\Frontend\Enums\FrontendResourceSourceKind;
use Capell\Frontend\Support\Cache\PublicPageRenderDataCache;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class BuildPublicRenderPerformanceReportAction
{
    use AsFake;
    use AsObject;

    public function handle(PublicPageRenderData $renderData, ?FrontendRenderContextData $context = null, ?int $lastRenderMilliseconds = null): PublicRenderPerformanceReportData
    {
        $plan = $renderData->resourcePlan;
        $runtime = $renderData->runtimeManifest;
        $resources = [...$plan->headResources, ...$plan->bodyEndResources];
        $styles = array_filter($resources, static fn (ResolvedFrontendResourceData $resource): bool => in_array($resource->kind, [FrontendResourceKind::Style, FrontendResourceKind::InlineStyle], true));
        $scripts = array_filter($resources, static fn (ResolvedFrontendResourceData $resource): bool => $resource->kind->isScript());
        $inline = array_filter($resources, static fn (ResolvedFrontendResourceData $resource): bool => $resource->kind->isInline());
        $remote = array_filter($resources, static fn (ResolvedFrontendResourceData $resource): bool => $resource->sourceKind === FrontendResourceSourceKind::External);
        $localStyles = array_filter($styles, static fn (ResolvedFrontendResourceData $resource): bool => is_string($resource->localPath) && is_file($resource->localPath));
        $localScripts = array_filter($scripts, static fn (ResolvedFrontendResourceData $resource): bool => is_string($resource->localPath) && is_file($resource->localPath));

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
                'css' => count($styles),
                'js' => count($scripts),
                'inline' => count($inline),
                'preloads' => count($plan->hints),
                'lazyActivations' => count($plan->lazyActivationGraphs),
                'remoteUnmeasurable' => count($remote),
                'mediaPreloads' => count($renderData->mediaHints),
            ],
            byteCounts: [
                'inline' => array_sum(array_map(static fn (ResolvedFrontendResourceData $resource): int => strlen((string) $resource->content), $inline)),
                'js' => $this->rawBytes($localScripts),
                'jsRaw' => $this->rawBytes($localScripts),
                'jsGzip' => $this->gzipBytes($localScripts),
                'css' => $this->rawBytes($localStyles),
                'cssRaw' => $this->rawBytes($localStyles),
                'cssGzip' => $this->gzipBytes($localStyles),
                'criticalCss' => array_sum(array_map(static fn (ResolvedFrontendResourceData $resource): int => $resource->criticalCssEligible ? strlen((string) $resource->content) : 0, $styles)),
            ],
            surrogateKeys: $renderData->surrogateKeys,
            assetReasons: array_map(static fn (ResolvedFrontendResourceData $resource): array => [
                'handle' => $resource->handle,
                'package' => $resource->package,
                'kind' => $resource->kind->value,
                'url' => $resource->url,
                'placement' => $resource->placement->value,
                'dependencies' => $resource->dependsOn,
            ], $resources),
            renderDataCacheKey: $context instanceof FrontendRenderContextData ? resolve(PublicPageRenderDataCache::class)->keyForContext($context) : null,
            layoutGraphKey: $renderData->layoutGraphKey(),
            lastRenderMilliseconds: $lastRenderMilliseconds,
        );
    }

    /** @param array<int, ResolvedFrontendResourceData> $resources */
    private function rawBytes(array $resources): int
    {
        return array_sum(array_map(
            static fn (ResolvedFrontendResourceData $resource): int => is_string($resource->localPath) ? (int) filesize($resource->localPath) : 0,
            $resources,
        ));
    }

    /** @param array<int, ResolvedFrontendResourceData> $resources */
    private function gzipBytes(array $resources): int
    {
        return array_sum(array_map(static function (ResolvedFrontendResourceData $resource): int {
            if (! is_string($resource->localPath)) {
                return 0;
            }

            $contents = file_get_contents($resource->localPath);
            $compressed = is_string($contents) ? gzencode($contents, 9) : false;

            return is_string($compressed) ? strlen($compressed) : 0;
        }, $resources));
    }
}

<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Models\PageUrl;
use Capell\Core\Support\Json\JsonCodec;
use Capell\Frontend\Data\Assets\FrontendResourceHintData;
use Capell\Frontend\Data\Assets\ResolvedFrontendResourceData;
use Capell\Frontend\Data\PublicPageRenderData;
use Capell\Frontend\Data\PublicRenderDataCacheDependencyData;
use Capell\Frontend\Data\StaticPageArtifactData;
use Capell\Frontend\Data\StaticPageArtifactSourceData;
use Illuminate\Support\Facades\Date;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Symfony\Component\HttpFoundation\Response;

class BuildStaticPageArtifactMetadataAction
{
    use AsFake;
    use AsObject;

    public function handle(PageUrl $pageUrl, PublicPageRenderData $renderData, StaticPageArtifactSourceData $source, Response $response, ?string $file = null): StaticPageArtifactData
    {
        return new StaticPageArtifactData(
            url: $pageUrl->url,
            file: $file,
            headers: $this->headers($response),
            dependencies: $this->dependencies($renderData, $source),
            runtime: $this->fingerprint($renderData->runtimeManifest->toArray()),
            assets: $this->assets($renderData),
            surrogateKeys: array_map(
                static fn (string $key): string => hash('sha256', $key),
                $renderData->extensionSurrogateKeys,
            ),
            generatedAt: Date::now()->toIso8601String(),
        );
    }

    /**
     * @return array<string, string>
     */
    private function headers(Response $response): array
    {
        return collect([
            'cache-control',
            'content-type',
            'etag',
            'x-frontend-cache',
        ])
            ->mapWithKeys(function (string $header) use ($response): array {
                $value = $response->headers->get($header);

                return $value === null ? [] : [$header => $value];
            })
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function dependencies(PublicPageRenderData $renderData, StaticPageArtifactSourceData $source): array
    {
        $dependencies = array_filter([
            'page' => $source->pageType !== null && $source->pageId !== null ? [
                'type' => $source->pageType,
                'id' => $source->pageId,
            ] : null,
            'site_id' => $source->siteId,
            'language_id' => $source->languageId,
            'layout_id' => $source->layoutId,
            'theme_id' => $source->themeId,
            'layout_graph_key' => $renderData->layoutGraphKey(),
        ], fn (mixed $value): bool => $value !== null);

        $metadata = $this->fingerprint($dependencies);
        $metadata['public_render_data'] = [
            'fingerprint' => hash('sha256', $renderData->extensionFingerprint),
            'cache_dependencies' => array_map(
                static fn (PublicRenderDataCacheDependencyData $dependency): string => hash('sha256', $dependency->identity()),
                $renderData->extensionCacheDependencies,
            ),
        ];

        return $metadata;
    }

    /**
     * @return array<string, mixed>
     */
    private function assets(PublicPageRenderData $renderData): array
    {
        $plan = $renderData->resourcePlan;

        return [
            'plan' => [
                'count' => count($plan->headResources) + count($plan->bodyEndResources) + count($plan->lazyActivationGraphs),
                'fingerprint' => $plan->fingerprint,
            ],
            'head' => $this->resourceFingerprint($plan->headResources),
            'body_end' => $this->resourceFingerprint($plan->bodyEndResources),
            'hints' => $this->fingerprint(array_map(static fn (FrontendResourceHintData $hint): array => $hint->toArray(), $plan->hints)),
        ];
    }

    /**
     * @param  array<int, ResolvedFrontendResourceData>  $resources
     * @return array{count: int, fingerprint: string}
     */
    private function resourceFingerprint(array $resources): array
    {
        $assets = collect($resources)
            ->map(fn (ResolvedFrontendResourceData $resource): array => [
                'token' => $resource->token,
                'kind' => $resource->kind->value,
                'url' => $resource->url,
                'placement' => $resource->placement->value,
            ])
            ->values()
            ->all();

        return $this->fingerprint($assets);
    }

    /**
     * @param  array<mixed>  $data
     * @return array{count: int, fingerprint: string}
     */
    private function fingerprint(array $data): array
    {
        $encoded = JsonCodec::encode($data);

        return [
            'count' => count($data),
            'fingerprint' => hash('sha256', $encoded),
        ];
    }
}

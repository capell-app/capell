<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\PageUrl;
use Capell\Core\Support\Json\JsonCodec;
use Capell\Frontend\Data\Assets\FrontendResourceHintData;
use Capell\Frontend\Data\Assets\ResolvedFrontendResourceData;
use Capell\Frontend\Data\PublicPageRenderData;
use Capell\Frontend\Data\StaticPageArtifactData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Symfony\Component\HttpFoundation\Response;

class BuildStaticPageArtifactMetadataAction
{
    use AsFake;
    use AsObject;

    public function handle(PageUrl $pageUrl, PublicPageRenderData $renderData, Response $response, ?string $file = null): StaticPageArtifactData
    {
        return new StaticPageArtifactData(
            url: $pageUrl->url,
            file: $file,
            headers: $this->headers($response),
            dependencies: $this->dependencies($renderData),
            runtime: $this->fingerprint($renderData->runtimeManifest->toArray()),
            assets: $this->assets($renderData),
            surrogateKeys: [],
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
    private function dependencies(PublicPageRenderData $renderData): array
    {
        $page = $renderData->page;

        $dependencies = array_filter([
            'page' => $page instanceof Model && $page instanceof Pageable ? [
                'type' => $page::class,
                'id' => (int) $page->getKey(),
            ] : null,
            'site_id' => $renderData->site?->getKey(),
            'language_id' => $renderData->language?->getKey(),
            'layout_id' => $renderData->layout?->getKey(),
            'theme_id' => $renderData->theme?->getKey(),
            'layout_graph_key' => $renderData->layoutGraphKey(),
        ], fn (mixed $value): bool => $value !== null);

        return $this->fingerprint($dependencies);
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

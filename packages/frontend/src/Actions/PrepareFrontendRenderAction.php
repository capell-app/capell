<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Frontend\Contracts\FrontendContextReader;
use Capell\Frontend\Data\FrontendRenderContextData;
use Capell\Frontend\Data\PreparedFrontendRenderData;
use Capell\Frontend\Data\PublicPageRenderData;
use Capell\Frontend\Events\FrontendRenderPreparing;
use Capell\Frontend\Support\Cache\PublicPageRenderDataCache;
use Capell\Frontend\Support\Render\FrontendResponseRendererRegistry;
use Illuminate\Support\Facades\Event;
use Lorisleiva\Actions\Concerns\AsObject;

final class PrepareFrontendRenderAction
{
    use AsObject;

    public function handle(
        FrontendContextReader $context,
        FrontendRenderContextData $renderContext,
    ): PreparedFrontendRenderData {
        $runtimeResolution = ResolveFrontendRuntimeAction::run($context);
        $renderer = resolve(FrontendResponseRendererRegistry::class)
            ->forRuntime($runtimeResolution->runtime);

        $renderContext->runtimeManifest = $runtimeResolution->runtimeManifest;
        $renderContext->publicRenderData = resolve(PublicPageRenderDataCache::class)
            ->remember($renderContext, fn (): PublicPageRenderData => BuildPublicPageRenderDataAction::run($renderContext));

        Event::dispatch(new FrontendRenderPreparing($context, $renderContext));

        $context->setFrontendData('runtimeManifest', $runtimeResolution->runtimeManifest);
        $context->setFrontendData('publicPageRenderData', $renderContext->publicRenderData);
        $context->setFrontendData('resourcePlan', $renderContext->publicRenderData->resourcePlan);
        $context->setFrontendData('mediaHints', $renderContext->publicRenderData->mediaHints);
        $context->setFrontendData(
            'lcpMediaUrl',
            $renderContext->publicRenderData->mediaHints[0]->mediaUrl
                ?? $renderContext->publicRenderData->mediaHints[0]->url
                ?? null,
        );
        $context->setFrontendData(
            'performanceReport',
            BuildPublicRenderPerformanceReportAction::run($renderContext->publicRenderData, $renderContext),
        );

        return new PreparedFrontendRenderData(
            runtime: $runtimeResolution->runtime,
            runtimeManifest: $runtimeResolution->runtimeManifest,
            renderer: $renderer,
            renderContext: $renderContext,
        );
    }
}

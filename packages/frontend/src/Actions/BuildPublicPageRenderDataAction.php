<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Frontend\Contracts\PublicContentWidgetPayloadBuilder;
use Capell\Frontend\Contracts\PublicLayoutGraphBuilder;
use Capell\Frontend\Contracts\PublicWidgetInteractionLocatorBuilder;
use Capell\Frontend\Data\FrontendAssetContextData;
use Capell\Frontend\Data\FrontendRenderContextData;
use Capell\Frontend\Data\FrontendRuntimeManifestData;
use Capell\Frontend\Data\PublicPageRenderData;
use Capell\Frontend\Enums\RenderingStrategyEnum;
use Lorisleiva\Actions\Concerns\AsObject;

class BuildPublicPageRenderDataAction
{
    use AsObject;

    public function handle(FrontendRenderContextData $context): PublicPageRenderData
    {
        $runtimeManifest = $context->runtimeManifest
            ?? FrontendRuntimeManifestData::forRenderingStrategy(RenderingStrategyEnum::BladeOnly);

        $assetManifest = BuildFrontendAssetManifestAction::run(new FrontendAssetContextData(
            page: $context->page,
            site: $context->site,
            language: $context->language,
            layout: $context->layout,
            theme: $context->theme,
            runtime: $runtimeManifest,
            widgetResourceUsages: BuildFrontendWidgetResourceUsagesAction::run($context),
        ));

        return new PublicPageRenderData(
            page: $context->page,
            site: $context->site,
            language: $context->language,
            layout: $context->layout,
            theme: $context->theme,
            layoutGraph: $this->layoutGraph($context),
            runtimeManifest: $runtimeManifest,
            assetManifest: $assetManifest,
            surrogateKeys: $this->surrogateKeys($context),
            mediaHints: BuildFrontendMediaHintsAction::run($context),
            contentWidgetPayloads: $this->contentWidgetPayloads($context),
            widgetInteractionLocators: $this->widgetInteractionLocators($context),
        );
    }

    /** @return array<string, string> */
    private function widgetInteractionLocators(FrontendRenderContextData $context): array
    {
        if (! app()->bound(PublicWidgetInteractionLocatorBuilder::class)) {
            return [];
        }

        return resolve(PublicWidgetInteractionLocatorBuilder::class)->build($context);
    }

    /** @return array<string, object> */
    private function contentWidgetPayloads(FrontendRenderContextData $context): array
    {
        if (! app()->bound(PublicContentWidgetPayloadBuilder::class)) {
            return [];
        }

        return resolve(PublicContentWidgetPayloadBuilder::class)->build($context);
    }

    private function layoutGraph(FrontendRenderContextData $context): ?object
    {
        if (! $context->page instanceof Page
            || ! $context->layout instanceof Layout
            || ! $context->language instanceof Language) {
            return null;
        }

        if (! app()->bound(PublicLayoutGraphBuilder::class)) {
            return null;
        }

        return resolve(PublicLayoutGraphBuilder::class)->build($context->layout, $context->page, $context->language);
    }

    /**
     * @return array<int, string>
     */
    private function surrogateKeys(FrontendRenderContextData $context): array
    {
        $keys = [];

        if ($context->page instanceof Page) {
            $keys[] = 'page-' . $context->page->getKey();
        }

        if ($context->site instanceof Site) {
            $keys[] = 'site-' . $context->site->getKey();
        }

        if ($context->language instanceof Language) {
            $keys[] = 'lang-' . $context->language->code;
        }

        return array_values(array_unique(array_filter(
            $keys,
            fn (string $key): bool => $key !== '',
        )));
    }
}

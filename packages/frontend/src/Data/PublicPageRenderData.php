<?php

declare(strict_types=1);

namespace Capell\Frontend\Data;

use Capell\Core\Contracts\PublicRenderData;
use Capell\Core\Data\Rendering\FrontendRuntimeManifestData;
use Capell\Frontend\Data\Assets\FrontendResourcePlanData;
use Capell\Frontend\Support\Render\PreparedLayoutGraph;
use Spatie\LaravelData\Data;

/**
 * Everything public Blade, fragments and exports read for one page render.
 * It holds hydrated Data only: the page, site, language, layout and theme
 * reach views through the model-free {@see PublicRenderContextData}.
 */
class PublicPageRenderData extends Data implements PublicRenderData
{
    /**
     * @param  array<int, string>  $surrogateKeys
     * @param  array<int, FrontendMediaHintData>  $mediaHints
     * @param  array<string, object>  $contentWidgetPayloads
     * @param  array<string, string>  $widgetInteractionLocators
     * @param  array<string, object>  $extensionData
     * @param  list<string>  $extensionSurrogateKeys
     * @param  list<PublicRenderDataCacheDependencyData>  $extensionCacheDependencies
     */
    public function __construct(
        public ?object $layoutGraph,
        public FrontendRuntimeManifestData $runtimeManifest,
        public FrontendResourcePlanData $resourcePlan,
        public array $surrogateKeys,
        public array $mediaHints = [],
        public array $contentWidgetPayloads = [],
        public array $widgetInteractionLocators = [],
        public array $extensionData = [],
        public string $extensionFingerprint = '',
        public array $extensionSurrogateKeys = [],
        public array $extensionCacheDependencies = [],
        public ?PublicRenderContextData $context = null,
    ) {}

    public function contentWidgetPayload(string $instanceId): ?object
    {
        return $this->contentWidgetPayloads[$instanceId] ?? null;
    }

    /**
     * Raw public HTML for this occurrence; null asks the layout provider to use
     * its normal widget renderer. Consume it once inside presentation wrappers.
     */
    public function preparedWidgetHtml(string $containerKey, string $widgetKey, int $occurrence = 1): ?string
    {
        return new PreparedLayoutGraph($this->layoutGraph)->widgetHtml($containerKey, $widgetKey, $occurrence);
    }

    public function widgetInteractionLocator(string $instanceId): ?string
    {
        return $this->widgetInteractionLocators[$instanceId] ?? null;
    }

    public function extensionData(string $key): ?object
    {
        return $this->extensionData[$key] ?? null;
    }

    public function layoutGraphKey(): ?string
    {
        if ($this->layoutGraph === null) {
            return null;
        }

        $layoutGraph = (array) $this->layoutGraph;
        $key = $layoutGraph['key'] ?? null;

        return is_string($key) ? $key : null;
    }
}

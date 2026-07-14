<?php

declare(strict_types=1);

namespace Capell\Frontend\Data;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Frontend\Data\Assets\FrontendResourcePlanData;
use Spatie\LaravelData\Data;

class PublicPageRenderData extends Data
{
    /**
     * @param  array<int, string>  $surrogateKeys
     * @param  array<int, FrontendMediaHintData>  $mediaHints
     * @param  array<string, object>  $contentWidgetPayloads
     * @param  array<string, string>  $widgetInteractionLocators
     */
    public function __construct(
        public ?Pageable $page,
        public ?Site $site,
        public ?Language $language,
        public ?Layout $layout,
        public ?Theme $theme,
        public ?object $layoutGraph,
        public FrontendRuntimeManifestData $runtimeManifest,
        public FrontendResourcePlanData $resourcePlan,
        public array $surrogateKeys,
        public array $mediaHints = [],
        public array $contentWidgetPayloads = [],
        public array $widgetInteractionLocators = [],
    ) {}

    public function contentWidgetPayload(string $instanceId): ?object
    {
        return $this->contentWidgetPayloads[$instanceId] ?? null;
    }

    public function widgetInteractionLocator(string $instanceId): ?string
    {
        return $this->widgetInteractionLocators[$instanceId] ?? null;
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

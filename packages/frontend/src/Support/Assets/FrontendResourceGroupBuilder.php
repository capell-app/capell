<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Assets;

use Capell\Core\Enums\PresentationLoadingStrategy;
use Capell\Frontend\Data\Assets\FrontendResourceData;
use Capell\Frontend\Data\FrontendAssetRequirementData;

class FrontendResourceGroupBuilder
{
    public function __construct(
        private readonly FrontendResourceRegistry $registry,
        private readonly string $groupKey,
    ) {}

    public function css(string $source, ?string $buildPath = null, PresentationLoadingStrategy $loading = PresentationLoadingStrategy::Eager): self
    {
        $this->registry->add($this->groupKey, new FrontendResourceData(
            handle: 'resource:' . hash('xxh128', $this->groupKey . ':css:' . $source),
            kind: FrontendAssetRequirementData::KIND_CSS,
            source: $source,
            buildPath: $buildPath,
            loadingStrategy: $loading,
        ));

        return $this;
    }

    public function js(
        string $source,
        ?string $buildPath = null,
        PresentationLoadingStrategy $loading = PresentationLoadingStrategy::Eager,
        bool $defer = false,
        bool $async = false,
        bool $module = true,
    ): self {
        $this->registry->add($this->groupKey, new FrontendResourceData(
            handle: 'resource:' . hash('xxh128', $this->groupKey . ':js:' . $source),
            kind: FrontendAssetRequirementData::KIND_JS,
            source: $source,
            buildPath: $buildPath,
            loadingStrategy: $loading,
            defer: $defer,
            async: $async,
            module: $module,
        ));

        return $this;
    }
}

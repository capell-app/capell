<?php

declare(strict_types=1);

namespace Capell\Frontend\Data;

/**
 * One public render data cache entry. The model identities let a cache hit
 * declare the models the render showed; null means they were not captured.
 */
final readonly class CachedPublicPageRenderData
{
    public function __construct(
        public PublicPageRenderData $renderData,
        public ?RenderedModelIdentitiesData $modelIdentities,
    ) {}
}

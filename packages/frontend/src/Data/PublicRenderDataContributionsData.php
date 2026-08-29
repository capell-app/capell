<?php

declare(strict_types=1);

namespace Capell\Frontend\Data;

/** Prepared contributor output shared by the cache key and render builder. */
final readonly class PublicRenderDataContributionsData
{
    /**
     * @param  array<string, object>  $values
     * @param  list<string>  $surrogateKeys
     * @param  list<PublicRenderDataCacheDependencyData>  $cacheDependencies
     */
    public function __construct(
        public array $values,
        public string $fingerprint,
        public array $surrogateKeys,
        public array $cacheDependencies,
    ) {}
}

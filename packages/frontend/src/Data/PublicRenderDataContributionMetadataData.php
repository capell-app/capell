<?php

declare(strict_types=1);

namespace Capell\Frontend\Data;

use RuntimeException;

final readonly class PublicRenderDataContributionMetadataData
{
    /** @param list<PublicRenderDataCacheDependencyData> $cacheDependencies */
    public function __construct(
        public string $fingerprint,
        public array $surrogateKeys = [],
        public array $cacheDependencies = [],
    ) {
        throw_if($this->fingerprint === '', RuntimeException::class, 'Public render-data contributors require a non-empty fingerprint.');

        foreach ($this->surrogateKeys as $key) {
            throw_unless(is_string($key) && preg_match('/\A[A-Za-z0-9_-]+\z/', $key) === 1, RuntimeException::class, 'Public render-data contributors require valid surrogate keys.');
        }

        foreach ($this->cacheDependencies as $dependency) {
            throw_unless($dependency instanceof PublicRenderDataCacheDependencyData, RuntimeException::class, 'Public render-data contributors require typed cache dependencies.');
        }
    }
}

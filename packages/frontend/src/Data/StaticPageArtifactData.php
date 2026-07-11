<?php

declare(strict_types=1);

namespace Capell\Frontend\Data;

use Spatie\LaravelData\Data;

class StaticPageArtifactData extends Data
{
    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $dependencies
     * @param  array<string, mixed>  $runtime
     * @param  array<string, mixed>  $assets
     * @param  array<int, string>  $surrogateKeys
     */
    public function __construct(
        public readonly string $url,
        public readonly ?string $file,
        public readonly array $headers,
        public readonly array $dependencies,
        public readonly array $runtime,
        public readonly array $assets,
        public readonly array $surrogateKeys,
        public readonly string $generatedAt,
    ) {}
}

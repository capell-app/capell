<?php

declare(strict_types=1);

namespace Capell\Frontend\Data\Assets;

use Spatie\LaravelData\Data;

final class RenderedFrontendResourcesData extends Data
{
    /**
     * @param  array<int, array<string, mixed>>  $lazyRuntimePayload
     * @param  list<string>  $scriptHashSources  CSP 'sha256-…' sources for every inline script emitted
     * @param  list<string>  $styleHashSources  CSP 'sha256-…' sources for every inline style emitted
     */
    public function __construct(
        public readonly string $headHtml,
        public readonly string $bodyEndHtml,
        public readonly array $lazyRuntimePayload,
        public readonly array $scriptHashSources = [],
        public readonly array $styleHashSources = [],
    ) {}
}

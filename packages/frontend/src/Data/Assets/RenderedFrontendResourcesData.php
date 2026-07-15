<?php

declare(strict_types=1);

namespace Capell\Frontend\Data\Assets;

use Spatie\LaravelData\Data;

final class RenderedFrontendResourcesData extends Data
{
    /** @param  array<int, array<string, mixed>>  $lazyRuntimePayload */
    public function __construct(
        public readonly string $headHtml,
        public readonly string $bodyEndHtml,
        public readonly array $lazyRuntimePayload,
    ) {}
}

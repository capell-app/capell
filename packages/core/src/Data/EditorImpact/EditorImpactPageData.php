<?php

declare(strict_types=1);

namespace Capell\Core\Data\EditorImpact;

use Spatie\LaravelData\Data;

final class EditorImpactPageData extends Data
{
    /**
     * @param  list<string>  $locales
     * @param  list<EditorImpactUrlData>  $urls
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly string $site,
        public readonly array $locales,
        public readonly array $urls,
    ) {}
}

<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Layouts;

use Spatie\LaravelData\Data;

final class LayoutImpactPageData extends Data
{
    /**
     * @param  list<string>  $locales
     * @param  list<LayoutImpactUrlData>  $urls
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly string $site,
        public readonly array $locales,
        public readonly array $urls,
    ) {}
}

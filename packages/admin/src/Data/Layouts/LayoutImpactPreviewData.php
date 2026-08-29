<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Layouts;

use Spatie\LaravelData\Data;

final class LayoutImpactPreviewData extends Data
{
    /**
     * @param  list<LayoutImpactPageData>  $pages
     */
    public function __construct(
        public readonly int $pageCount,
        public readonly int $siteCount,
        public readonly int $localeCount,
        public readonly array $pages,
    ) {}
}

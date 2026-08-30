<?php

declare(strict_types=1);

namespace Capell\Core\Data\EditorImpact;

use Spatie\LaravelData\Data;

final class EditorImpactPreviewData extends Data
{
    /**
     * @param  list<EditorImpactPageData>  $pages
     */
    public function __construct(
        public readonly int $pageCount,
        public readonly int $siteCount,
        public readonly int $localeCount,
        public readonly array $pages,
    ) {}
}

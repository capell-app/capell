<?php

declare(strict_types=1);

namespace Capell\Frontend\Data;

use Capell\Core\Contracts\PublicRenderData;
use Spatie\LaravelData\Data;

final class PublicPageResultsContentData extends Data implements PublicRenderData
{
    public function __construct(
        public readonly int $resultCount,
        public readonly bool $empty,
        public readonly string $renderedItems,
        public readonly string $renderedNoResults,
        public readonly string $renderedPagination,
    ) {}
}

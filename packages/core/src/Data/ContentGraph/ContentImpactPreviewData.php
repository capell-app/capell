<?php

declare(strict_types=1);

namespace Capell\Core\Data\ContentGraph;

use Spatie\LaravelData\Data;

final class ContentImpactPreviewData extends Data
{
    /**
     * @param  array<int, ContentImpactGroupData>  $groups
     */
    public function __construct(
        public readonly bool $blocked,
        public readonly int $strongCount,
        public readonly int $weakCount,
        public readonly int $informationalCount,
        public readonly array $groups,
    ) {}
}

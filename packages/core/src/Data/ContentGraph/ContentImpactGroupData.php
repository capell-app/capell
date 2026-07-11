<?php

declare(strict_types=1);

namespace Capell\Core\Data\ContentGraph;

use Capell\Core\Enums\ContentGraph\ContentGraphEdgeStrength;
use Spatie\LaravelData\Data;

final class ContentImpactGroupData extends Data
{
    /**
     * @param  array<int, int>  $recordIds
     */
    public function __construct(
        public readonly string $label,
        public readonly string $modelType,
        public readonly ContentGraphEdgeStrength $strongestStrength,
        public readonly int $count,
        public readonly array $recordIds,
    ) {}
}

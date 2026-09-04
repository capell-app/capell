<?php

declare(strict_types=1);

namespace Capell\Core\Data\ContentGraph;

use Capell\Core\Data\EditorImpact\EditorImpactDependencyData;
use Capell\Core\Enums\ContentGraph\ContentGraphEdgeStrength;
use Spatie\LaravelData\Data;

final class ContentImpactGroupData extends Data
{
    /**
     * @param  list<EditorImpactDependencyData>  $dependencies
     */
    public function __construct(
        public readonly string $label,
        public readonly ContentGraphEdgeStrength $strongestStrength,
        public readonly int $count,
        public readonly array $dependencies,
    ) {}
}

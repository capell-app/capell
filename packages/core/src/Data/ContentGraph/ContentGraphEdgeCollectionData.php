<?php

declare(strict_types=1);

namespace Capell\Core\Data\ContentGraph;

use Spatie\LaravelData\Data;

final class ContentGraphEdgeCollectionData extends Data
{
    /**
     * @param  array<int, ContentGraphEdgeData>  $edges
     */
    public function __construct(
        public readonly array $edges,
    ) {}

    /**
     * @param  array<int, ContentGraphEdgeData>  $edges
     */
    public static function make(array $edges = []): self
    {
        return new self(array_values($edges));
    }
}

<?php

declare(strict_types=1);

namespace Capell\Core\Data\EditorImpact;

use Spatie\LaravelData\Data;

final class EditorImpactConsequenceData extends Data
{
    /** @param list<string> $urls */
    public function __construct(
        public readonly string $label,
        public readonly string $description,
        public readonly int $count,
        public readonly array $urls = [],
        public readonly ?float $estimatedSeconds = null,
        public readonly ?string $estimateBasis = null,
    ) {}
}

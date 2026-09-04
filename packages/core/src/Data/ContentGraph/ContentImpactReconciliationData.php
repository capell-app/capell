<?php

declare(strict_types=1);

namespace Capell\Core\Data\ContentGraph;

use Spatie\LaravelData\Data;

final class ContentImpactReconciliationData extends Data
{
    /**
     * @param  list<string>  $predictedSurfaces
     * @param  list<string>  $actualSurfaces
     * @param  list<string>  $missingSurfaces
     * @param  list<string>  $unexpectedSurfaces
     */
    public function __construct(
        public readonly array $predictedSurfaces,
        public readonly array $actualSurfaces,
        public readonly array $missingSurfaces,
        public readonly array $unexpectedSurfaces,
        public readonly bool $drifted,
    ) {}
}

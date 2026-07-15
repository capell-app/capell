<?php

declare(strict_types=1);

namespace Capell\Frontend\Data\Assets;

use Spatie\LaravelData\Data;

final class FrontendResourcePlanData extends Data
{
    /**
     * @param  array<int, ResolvedFrontendResourceData>  $headResources
     * @param  array<int, ResolvedFrontendResourceData>  $bodyEndResources
     * @param  array<int, FrontendResourceActivationPlanData>  $lazyActivationGraphs
     * @param  array<int, FrontendResourceHintData>  $hints
     * @param  array<string, string>  $aliases
     * @param  array<int, array<string, mixed>>  $diagnostics
     * @param  array<string, array<int, string>>  $cspOrigins
     */
    public function __construct(
        public readonly array $headResources,
        public readonly array $bodyEndResources,
        public readonly array $lazyActivationGraphs,
        public readonly array $hints,
        public readonly array $aliases,
        public readonly array $diagnostics,
        public readonly array $cspOrigins,
        public readonly string $fingerprint,
    ) {}
}

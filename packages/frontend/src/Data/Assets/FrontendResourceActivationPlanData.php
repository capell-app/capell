<?php

declare(strict_types=1);

namespace Capell\Frontend\Data\Assets;

use Capell\Core\Enums\PresentationLoadingStrategy;
use Spatie\LaravelData\Data;

final class FrontendResourceActivationPlanData extends Data
{
    /** @param  array<int, array<int, ResolvedFrontendResourceData>>  $dependencyLayers */
    public function __construct(
        public readonly string $target,
        public readonly PresentationLoadingStrategy $loadingStrategy,
        public readonly array $dependencyLayers,
    ) {}
}

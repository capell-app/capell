<?php

declare(strict_types=1);

namespace Capell\Frontend\Data;

use Capell\Frontend\Enums\RenderHookLocation;

final class RenderHookFragmentReferenceData
{
    public function __construct(
        public readonly string $token,
        public readonly string $stableKey,
        public readonly RenderHookLocation $location,
        public readonly ?string $scenario = null,
        public readonly ?string $target = null,
    ) {}
}

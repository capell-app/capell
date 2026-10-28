<?php

declare(strict_types=1);

namespace Capell\Frontend\Data;

use Capell\Frontend\Enums\FrontendComponentTarget;

/** @internal */
final readonly class FrontendComponentContributionData
{
    public function __construct(
        public string $name,
        public string $component,
        public FrontendComponentTarget $target,
    ) {}
}

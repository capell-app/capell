<?php

declare(strict_types=1);

namespace Capell\Frontend\Data;

use Spatie\LaravelData\Data;

class PublicRenderPerformanceBudgetResultData extends Data
{
    /**
     * @param  array<int, string>  $failures
     */
    public function __construct(
        public readonly bool $passes,
        public readonly array $failures,
    ) {}
}

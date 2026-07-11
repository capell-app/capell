<?php

declare(strict_types=1);

namespace Capell\Core\Data;

use Spatie\LaravelData\Data;

final class BlazeOptimizationData extends Data
{
    public function __construct(
        public bool $compile = true,
        public bool $memo = false,
        public bool $fold = false,
    ) {}
}

<?php

declare(strict_types=1);

namespace Capell\Frontend\Data;

use Spatie\LaravelData\Data;

final class CacheInvalidationPlanData extends Data
{
    /**
     * @param  array<int, CacheInvalidationRule>  $rules
     */
    public function __construct(public readonly array $rules) {}

    public static function emptyPlan(): self
    {
        return new self([]);
    }
}

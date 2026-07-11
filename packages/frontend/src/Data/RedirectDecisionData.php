<?php

declare(strict_types=1);

namespace Capell\Frontend\Data;

use Spatie\LaravelData\Data;

class RedirectDecisionData extends Data
{
    public function __construct(
        public string $targetUrl,
        public int $statusCode = 301,
    ) {}
}

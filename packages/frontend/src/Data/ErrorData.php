<?php

declare(strict_types=1);

namespace Capell\Frontend\Data;

use Spatie\LaravelData\Data;

class ErrorData extends Data
{
    public function __construct(
        public int $status,
        public ?string $message = null,
    ) {}
}

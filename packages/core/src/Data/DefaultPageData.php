<?php

declare(strict_types=1);

namespace Capell\Core\Data;

use Closure;
use Spatie\LaravelData\Data;

final class DefaultPageData extends Data
{
    public function __construct(
        public string $key,
        public ?string $label = null,
        public ?Closure $callback = null,
    ) {}
}

<?php

declare(strict_types=1);

namespace Capell\Core\Data\Theme;

use Spatie\LaravelData\Data;

class ColorData extends Data
{
    public function __construct(
        public string $name,
        public string $value,
        public ?string $darkValue = null,
    ) {}
}

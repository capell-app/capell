<?php

declare(strict_types=1);

namespace Capell\Core\ThemeStudio\Data;

use Spatie\LaravelData\Data;

class ThemeOverrideData extends Data
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function __construct(
        public string $themeKey,
        public ?string $presetKey = null,
        public array $values = [],
    ) {}
}

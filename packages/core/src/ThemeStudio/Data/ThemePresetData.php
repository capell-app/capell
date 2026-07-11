<?php

declare(strict_types=1);

namespace Capell\Core\ThemeStudio\Data;

use Spatie\LaravelData\Data;

class ThemePresetData extends Data
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $description,
        public string $previewImage,
        public array $values = [],
    ) {}
}

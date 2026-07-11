<?php

declare(strict_types=1);

namespace Capell\Core\Data\Presentation;

use Spatie\LaravelData\Data;

class PresentationPresetData extends Data
{
    /**
     * @param  array<string, mixed>  $settings
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $icon,
        public string $description,
        public array $settings = [],
        public bool $advanced = false,
    ) {}
}

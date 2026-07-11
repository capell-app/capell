<?php

declare(strict_types=1);

namespace Capell\Core\ThemeStudio\Data;

use Spatie\LaravelData\Data;

final class BookingEntryPointData extends Data
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $label,
        public string $url,
        public ?string $description = null,
        public ?string $serviceKey = null,
        public array $meta = [],
    ) {}
}

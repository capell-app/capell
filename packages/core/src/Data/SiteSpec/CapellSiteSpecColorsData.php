<?php

declare(strict_types=1);

namespace Capell\Core\Data\SiteSpec;

use Spatie\LaravelData\Data;

final class CapellSiteSpecColorsData extends Data
{
    public function __construct(
        public readonly ?string $primary = null,
        public readonly ?string $secondary = null,
        public readonly ?string $accent = null,
    ) {}
}

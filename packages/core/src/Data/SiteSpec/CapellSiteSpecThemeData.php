<?php

declare(strict_types=1);

namespace Capell\Core\Data\SiteSpec;

use Spatie\LaravelData\Data;

final class CapellSiteSpecThemeData extends Data
{
    public function __construct(
        public readonly string $key,
        public readonly CapellSiteSpecColorsData $colors = new CapellSiteSpecColorsData,
        public readonly ?string $fontFamily = null,
        public readonly ?string $linkColor = null,
        public readonly ?string $linkColorActive = null,
        public readonly ?string $container = null,
        public readonly ?string $customCss = null,
    ) {}
}

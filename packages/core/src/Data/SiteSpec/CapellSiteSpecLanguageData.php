<?php

declare(strict_types=1);

namespace Capell\Core\Data\SiteSpec;

use Spatie\LaravelData\Data;

final class CapellSiteSpecLanguageData extends Data
{
    public function __construct(
        public readonly string $code = 'en',
        public readonly string $name = 'English',
        public readonly string $locale = 'en_GB',
        public readonly string $flag = 'gb',
        public readonly bool $default = true,
    ) {}
}

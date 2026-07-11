<?php

declare(strict_types=1);

namespace Capell\Core\Data\SiteSpec;

use Spatie\LaravelData\Data;

final class CapellSiteSpecSiteData extends Data
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $businessName = null,
        public readonly ?string $organisationType = null,
        public readonly ?string $description = null,
    ) {}
}

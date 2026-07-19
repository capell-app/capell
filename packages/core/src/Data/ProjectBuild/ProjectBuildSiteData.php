<?php

declare(strict_types=1);

namespace Capell\Core\Data\ProjectBuild;

use Spatie\LaravelData\Data;

final class ProjectBuildSiteData extends Data
{
    /** @param list<string> $locales */
    public function __construct(
        public readonly string $key,
        public readonly string $defaultLocale,
        public readonly array $locales,
    ) {}
}

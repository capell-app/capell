<?php

declare(strict_types=1);

namespace Capell\Core\Data\ProjectBuild;

use Spatie\LaravelData\Data;

final class ProjectBuildRouteData extends Data
{
    public function __construct(
        public readonly string $siteKey,
        public readonly string $locale,
        public readonly string $path,
    ) {}
}

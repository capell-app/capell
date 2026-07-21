<?php

declare(strict_types=1);

namespace Capell\Core\Data\ProjectBuild;

use Spatie\LaravelData\Data;

final class ProjectBuildInstalledPackageData extends Data
{
    public function __construct(
        public readonly string $name,
        public readonly string $version,
        public readonly string $releaseIdentity,
    ) {}
}

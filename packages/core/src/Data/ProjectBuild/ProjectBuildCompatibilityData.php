<?php

declare(strict_types=1);

namespace Capell\Core\Data\ProjectBuild;

use Spatie\LaravelData\Data;

final class ProjectBuildCompatibilityData extends Data
{
    /** @param list<string> $platforms */
    public function __construct(
        public readonly string $capell,
        public readonly string $php,
        public readonly array $platforms,
    ) {}
}

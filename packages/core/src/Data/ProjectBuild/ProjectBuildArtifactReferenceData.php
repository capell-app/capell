<?php

declare(strict_types=1);

namespace Capell\Core\Data\ProjectBuild;

use Spatie\LaravelData\Data;

final class ProjectBuildArtifactReferenceData extends Data
{
    public function __construct(
        public readonly string $key,
        public readonly string $type,
        public readonly string $path,
        public readonly string $digest,
        public readonly int $sizeBytes,
        public readonly string $mediaType,
    ) {}
}

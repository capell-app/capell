<?php

declare(strict_types=1);

namespace Capell\Core\Data\ProjectBuild;

use Spatie\LaravelData\Data;

final class ProjectBuildSiteSpecReferenceData extends Data
{
    public function __construct(
        public readonly int $schemaVersion,
        public readonly string $key,
        public readonly string $type,
        public readonly string $path,
        public readonly string $digest,
        public readonly int $sizeBytes,
        public readonly string $mediaType,
    ) {}

    public function artifactReference(): ProjectBuildArtifactReferenceData
    {
        return new ProjectBuildArtifactReferenceData(
            key: $this->key,
            type: $this->type,
            path: $this->path,
            digest: $this->digest,
            sizeBytes: $this->sizeBytes,
            mediaType: $this->mediaType,
        );
    }
}

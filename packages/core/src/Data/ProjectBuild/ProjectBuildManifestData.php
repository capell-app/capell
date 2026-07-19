<?php

declare(strict_types=1);

namespace Capell\Core\Data\ProjectBuild;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

final class ProjectBuildManifestData extends Data
{
    /**
     * @param  list<ProjectBuildArtifactReferenceData>  $artifacts
     * @param  list<ProjectBuildPackageData>  $packages
     * @param  list<ProjectBuildSiteData>  $sites
     * @param  list<ProjectBuildRouteData>  $routes
     */
    public function __construct(
        public readonly int $schemaVersion,
        public readonly string $buildId,
        public readonly string $createdAt,
        public readonly ProjectBuildArtifactReferenceData $siteSpec,
        #[DataCollectionOf(ProjectBuildArtifactReferenceData::class)]
        public readonly array $artifacts,
        #[DataCollectionOf(ProjectBuildPackageData::class)]
        public readonly array $packages,
        #[DataCollectionOf(ProjectBuildSiteData::class)]
        public readonly array $sites,
        #[DataCollectionOf(ProjectBuildRouteData::class)]
        public readonly array $routes,
        public readonly ProjectBuildCompatibilityData $compatibility,
        public readonly ProjectBuildSignatureData $signature,
    ) {}
}

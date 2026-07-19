<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\ProjectBuild;

use Capell\Core\Data\ProjectBuild\ProjectBuildArtifactReferenceData;

interface ProjectBuildArtifactHandler
{
    public const string TAG = 'capell.project-build.artifact-handler';

    public function type(): string;

    public function validate(ProjectBuildArtifactReferenceData $artifact, string $bytes): void;
}

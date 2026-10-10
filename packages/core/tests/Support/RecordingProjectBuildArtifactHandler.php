<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

use Capell\Core\Contracts\ProjectBuild\ProjectBuildArtifactHandler;
use Capell\Core\Data\ProjectBuild\ProjectBuildArtifactReferenceData;
use Override;

final class RecordingProjectBuildArtifactHandler implements ProjectBuildArtifactHandler
{
    public int $calls = 0;

    #[Override]
    public function type(): string
    {
        return 'capell-theme';
    }

    #[Override]
    public function validate(ProjectBuildArtifactReferenceData $artifact, string $bytes): void
    {
        $this->calls++;
    }
}

<?php

declare(strict_types=1);

namespace Capell\Core\Actions\ProjectBuild;

use Capell\Core\Data\ProjectBuild\ProjectBuildArtifactReferenceData;
use Capell\Core\Data\ProjectBuild\ProjectBuildManifestData;
use Capell\Core\Support\ProjectBuild\ProjectBuildArtifactHandlerRegistry;
use Closure;
use Lorisleiva\Actions\Concerns\AsObject;

final class ValidateProjectBuildManifestBundleAction
{
    use AsObject;

    public function __construct(
        private readonly ReadProjectBuildManifestAction $reader,
        private readonly ProjectBuildArtifactHandlerRegistry $artifacts,
    ) {}

    /** @param Closure(ProjectBuildArtifactReferenceData): string $readArtifact */
    public function handle(string $manifestJson, string $publicKey, Closure $readArtifact): ProjectBuildManifestData
    {
        $manifest = $this->reader->handle($manifestJson);
        VerifyProjectBuildManifestSignatureAction::run($manifest, $publicKey);

        $references = [
            $manifest->siteSpec->artifactReference(),
            ...$manifest->artifacts,
        ];

        foreach ($references as $reference) {
            $bytes = $readArtifact($reference);
            $this->artifacts->validate($reference, $bytes);
        }

        return $manifest;
    }
}

<?php

declare(strict_types=1);

namespace Capell\Core\Actions\ProjectBuild;

use Capell\Core\Data\ProjectBuild\ProjectBuildArtifactReferenceData;
use Capell\Core\Data\ProjectBuild\ProjectBuildManifestData;
use Capell\Core\Support\ProjectBuild\ProjectBuildArtifactHandlerRegistry;
use Closure;
use Illuminate\Validation\ValidationException;
use JsonException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class ValidateProjectBuildManifestBundleAction
{
    use AsFake;
    use AsObject;

    public function __construct(
        private readonly ProjectBuildArtifactHandlerRegistry $artifacts,
    ) {}

    /** @param Closure(ProjectBuildArtifactReferenceData): string $readArtifact */
    public function handle(string $manifestJson, string $publicKey, Closure $readArtifact): ProjectBuildManifestData
    {
        try {
            $signedPayload = json_decode($manifestJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ValidationException::withMessages(['manifest' => 'The project build manifest must contain valid JSON.']);
        }

        if (! is_array($signedPayload) || array_is_list($signedPayload)) {
            throw ValidationException::withMessages(['manifest' => 'The project build manifest must contain a JSON object.']);
        }

        VerifyProjectBuildManifestSignatureAction::run($signedPayload, $publicKey);
        $manifest = ReadProjectBuildManifestAction::run($manifestJson);

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

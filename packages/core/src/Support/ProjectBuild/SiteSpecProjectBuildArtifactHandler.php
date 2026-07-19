<?php

declare(strict_types=1);

namespace Capell\Core\Support\ProjectBuild;

use Capell\Core\Actions\ValidateSiteSpecAction;
use Capell\Core\Contracts\ProjectBuild\ProjectBuildArtifactHandler;
use Capell\Core\Data\ProjectBuild\ProjectBuildArtifactReferenceData;
use Capell\Core\Support\Json\JsonCodec;
use Illuminate\Validation\ValidationException;
use JsonException;

final class SiteSpecProjectBuildArtifactHandler implements ProjectBuildArtifactHandler
{
    public function type(): string
    {
        return 'site-spec';
    }

    public function validate(ProjectBuildArtifactReferenceData $artifact, string $bytes): void
    {
        try {
            $payload = JsonCodec::decode($bytes);
        } catch (JsonException) {
            throw ValidationException::withMessages(['siteSpec' => 'The SiteSpec artifact must contain valid JSON.']);
        }

        if (! is_array($payload) || array_is_list($payload)) {
            throw ValidationException::withMessages(['siteSpec' => 'The SiteSpec artifact must contain a JSON object.']);
        }

        $result = ValidateSiteSpecAction::run($payload, [], [], []);
        if (! $result['valid']) {
            throw ValidationException::withMessages($result['errors']);
        }
    }
}

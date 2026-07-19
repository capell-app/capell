<?php

declare(strict_types=1);

namespace Capell\Core\Actions\ProjectBuild;

use Capell\Core\Data\ProjectBuild\ProjectBuildManifestData;
use Lorisleiva\Actions\Concerns\AsObject;

/** @method static string run(ProjectBuildManifestData $manifest) */
final class CanonicalizeProjectBuildManifestSigningInputAction
{
    use AsObject;

    public function handle(ProjectBuildManifestData $manifest): string
    {
        $payload = $manifest->toArray();
        unset($payload['signature']['value']);

        return CanonicalizeProjectBuildManifestAction::run($payload);
    }
}

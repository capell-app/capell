<?php

declare(strict_types=1);

namespace Capell\Core\Actions\ProjectBuild;

use Capell\Core\Data\ProjectBuild\ProjectBuildManifestData;
use Lorisleiva\Actions\Concerns\AsObject;

/** @method static string run(array<string, mixed>|ProjectBuildManifestData $manifest) */
final class CanonicalizeProjectBuildManifestSigningInputAction
{
    use AsObject;

    /** @param array<string, mixed>|ProjectBuildManifestData $manifest */
    public function handle(array|ProjectBuildManifestData $manifest): string
    {
        $payload = $manifest instanceof ProjectBuildManifestData ? $manifest->toArray() : $manifest;
        unset($payload['signature']['value']);

        return CanonicalizeProjectBuildManifestAction::run($payload);
    }
}

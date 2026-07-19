<?php

declare(strict_types=1);

namespace Capell\Core\Actions\ProjectBuild;

use Capell\Core\Data\ProjectBuild\ProjectBuildManifestData;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsObject;

/** @method static string run(array<string, mixed>|ProjectBuildManifestData $manifest) */
final class CanonicalizeProjectBuildManifestSigningInputAction
{
    use AsObject;

    /** @param array<string, mixed>|ProjectBuildManifestData $manifest */
    public function handle(array|ProjectBuildManifestData $manifest): string
    {
        $payload = $manifest instanceof ProjectBuildManifestData ? $manifest->toArray() : $manifest;
        if (! is_array($payload['signature'] ?? null)) {
            throw new InvalidArgumentException('Project build manifest signing metadata must be an object.');
        }

        unset($payload['signature']['value']);

        return CanonicalizeProjectBuildManifestAction::run($payload);
    }
}

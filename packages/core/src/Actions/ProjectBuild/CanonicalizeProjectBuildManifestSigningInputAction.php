<?php

declare(strict_types=1);

namespace Capell\Core\Actions\ProjectBuild;

use Capell\Core\Data\ProjectBuild\ProjectBuildManifestData;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/** @method static string run(array<string, mixed>|ProjectBuildManifestData $manifest) */
final class CanonicalizeProjectBuildManifestSigningInputAction
{
    use AsFake;
    use AsObject;

    /** @param array<string, mixed>|ProjectBuildManifestData $manifest */
    public function handle(array|ProjectBuildManifestData $manifest): string
    {
        $payload = $manifest instanceof ProjectBuildManifestData ? $manifest->toArray() : $manifest;
        throw_unless(is_array($payload['signature'] ?? null), InvalidArgumentException::class, 'Project build manifest signing metadata must be an object.');

        unset($payload['signature']['value']);

        return CanonicalizeProjectBuildManifestAction::run($payload);
    }
}

<?php

declare(strict_types=1);

namespace Capell\Core\Actions\ProjectBuild;

use Capell\Core\Data\ProjectBuild\ProjectBuildManifestData;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/** @method static string run(array<string, mixed>|ProjectBuildManifestData $manifest) */
final class CanonicalizeProjectBuildManifestAction
{
    use AsFake;
    use AsObject;

    /** @param array<string, mixed>|ProjectBuildManifestData $manifest */
    public function handle(array|ProjectBuildManifestData $manifest): string
    {
        return json_encode(
            $this->normalize($manifest instanceof ProjectBuildManifestData ? $manifest->toArray() : $manifest),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    private function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map($this->normalize(...), $value);
        }

        ksort($value, SORT_STRING);

        return array_map($this->normalize(...), $value);
    }
}

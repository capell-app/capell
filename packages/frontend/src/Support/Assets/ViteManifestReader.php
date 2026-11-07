<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Assets;

use Illuminate\Filesystem\Filesystem;

/**
 * Reads and decodes a Vite manifest once per request. The container binds it
 * scoped, so a rebuilt manifest is seen by the next request or queued job,
 * while one render that expands many Vite resources decodes each build
 * directory's manifest only once.
 */
final class ViteManifestReader
{
    /** @var array<string, mixed> */
    private array $manifests = [];

    public function __construct(private readonly Filesystem $files) {}

    public function read(string $path): mixed
    {
        if (! array_key_exists($path, $this->manifests)) {
            $this->manifests[$path] = json_decode($this->files->get($path), true, flags: JSON_THROW_ON_ERROR);
        }

        return $this->manifests[$path];
    }
}

<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Assets;

use Illuminate\Filesystem\Filesystem;

final readonly class ViteConfigurationLocator
{
    public function __construct(private Filesystem $files) {}

    public function find(): ?string
    {
        foreach (['vite.config.js', 'vite.config.mjs', 'vite.config.ts'] as $candidate) {
            $path = base_path($candidate);
            if ($this->files->isFile($path)) {
                return $path;
            }
        }

        return null;
    }
}

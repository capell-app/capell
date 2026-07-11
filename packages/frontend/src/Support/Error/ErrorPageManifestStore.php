<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Error;

use Capell\Core\Support\Json\JsonCodec;
use Illuminate\Support\Facades\File;

final class ErrorPageManifestStore
{
    public function path(): string
    {
        return storage_path('framework/capell-error-pages.json');
    }

    /** @return array<string, mixed> */
    public function read(): array
    {
        if (! File::exists($this->path())) {
            return $this->defaults();
        }

        $decoded = JsonCodec::decodeArray(File::get($this->path()), $this->defaults());

        return array_replace_recursive($this->defaults(), $decoded);
    }

    /** @param array<string, mixed> $manifest */
    public function write(array $manifest): void
    {
        File::ensureDirectoryExists(dirname($this->path()));
        File::put($this->path(), JsonCodec::encode(array_replace_recursive($this->defaults(), $manifest), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /** @param array<int, array<string, mixed>> $entries */
    public function replaceSite(int $siteId, array $entries): void
    {
        $manifest = $this->read();
        $siteKey = (string) $siteId;

        $manifest['sites'][$siteKey] = ['entries' => array_values($entries)];

        $this->write($manifest);
    }

    /** @return array<string, mixed> */
    private function defaults(): array
    {
        return [
            'sites' => [],
        ];
    }
}

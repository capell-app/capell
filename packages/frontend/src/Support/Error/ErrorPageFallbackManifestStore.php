<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Error;

use Capell\Core\Support\Json\JsonCodec;
use Illuminate\Support\Facades\File;

final class ErrorPageFallbackManifestStore
{
    public function path(): string
    {
        return storage_path('framework/capell-error-pages-fallback.json');
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

    /** @param array<int, array<string, string>> $copy */
    public function setHost(string $host, string $logoUrl, array $copy): void
    {
        $manifest = $this->read();
        $manifest['hosts'][$host] = [
            'logo_url' => $logoUrl,
            'copy' => $copy,
        ];

        $this->write($manifest);
    }

    /** @param array<int, array<string, string>> $copy */
    public function setDefault(string $logoUrl, array $copy): void
    {
        $manifest = $this->read();
        $manifest['default'] = [
            'logo_url' => $logoUrl,
            'copy' => $copy,
        ];

        $this->write($manifest);
    }

    /** @return array<string, mixed> */
    private function defaults(): array
    {
        return [
            'default' => ['logo_url' => null, 'copy' => []],
            'hosts' => [],
        ];
    }
}

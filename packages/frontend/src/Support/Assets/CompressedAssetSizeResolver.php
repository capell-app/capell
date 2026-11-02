<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Assets;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * Measures gzip size for local assets. Only the admin overlay, diagnostics and
 * budget assertion need this, so it is never called while serving a public render.
 * Results are cached by content hash, so unchanged assets are compressed once.
 */
class CompressedAssetSizeResolver
{
    private const int TTL_SECONDS = 86400;

    public function gzipBytes(string $path): int
    {
        if (! is_file($path)) {
            return 0;
        }

        $hash = hash_file('xxh128', $path);

        if (! is_string($hash)) {
            return 0;
        }

        return (int) $this->store()->remember(
            'capell:frontend:asset-gzip-bytes:' . $hash,
            self::TTL_SECONDS,
            fn (): int => $this->compress($path),
        );
    }

    protected function compress(string $path): int
    {
        $contents = file_get_contents($path);
        $compressed = is_string($contents) ? gzencode($contents, 9) : false;

        return is_string($compressed) ? strlen($compressed) : 0;
    }

    private function store(): Repository
    {
        return Cache::store();
    }
}

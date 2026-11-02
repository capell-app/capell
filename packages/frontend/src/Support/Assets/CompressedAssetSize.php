<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Assets;

use Illuminate\Contracts\Cache\Repository;
use Throwable;

/**
 * Measures the gzip size of a local build asset once per file version.
 *
 * The cache key carries the real path, byte size and modification time, so a rebuilt asset is measured
 * afresh while an unchanged one is never compressed twice, in this request or any later one.
 */
final class CompressedAssetSize
{
    private const string CACHE_PREFIX = 'capell:frontend:asset-gzip-bytes:';

    private const int GZIP_LEVEL = 9;

    // Long enough to outlive any deploy cycle; old asset versions then age out of the store.
    private const int CACHE_SECONDS = 2592000;

    /** @var array<string, int> */
    private array $measured = [];

    public function __construct(private readonly Repository $cache) {}

    public function bytes(string $path): int
    {
        clearstatcache(true, $path);
        $realPath = realpath($path);
        $size = $realPath === false ? false : @filesize($realPath);
        $modifiedAt = $realPath === false ? false : @filemtime($realPath);

        if ($realPath === false || $size === false || $modifiedAt === false) {
            return 0;
        }

        $key = self::CACHE_PREFIX . hash('xxh128', $realPath . '|' . $size . '|' . $modifiedAt);

        if (array_key_exists($key, $this->measured)) {
            return $this->measured[$key];
        }

        try {
            $bytes = $this->cache->remember($key, self::CACHE_SECONDS, fn (): int => $this->compress($realPath));
        } catch (Throwable) {
            $bytes = $this->compress($realPath);
        }

        return $this->measured[$key] = is_int($bytes) ? $bytes : $this->compress($realPath);
    }

    private function compress(string $path): int
    {
        $contents = @file_get_contents($path);
        $compressed = is_string($contents) ? gzencode($contents, self::GZIP_LEVEL) : false;

        return is_string($compressed) ? strlen($compressed) : 0;
    }
}

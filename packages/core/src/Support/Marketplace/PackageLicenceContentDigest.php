<?php

declare(strict_types=1);

namespace Capell\Core\Support\Marketplace;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class PackageLicenceContentDigest
{
    public const string MARKER = 'capell-licence.json';

    public const string GIT_METADATA = '.git';

    private const int MAX_MEMBERS = 16384;

    private const int MAX_PATH_BYTES = 2048;

    private const int MAX_CONTENT_BYTES = 256 * 1024 * 1024;

    /**
     * The v2 digest covers sorted, length-prefixed relative paths and fixed-size SHA-256 file hashes.
     * Git metadata and the root envelope are auxiliary to both supported install profiles.
     */
    public function calculate(string $directory): ?string
    {
        $directory = rtrim($directory, '/\\');
        if (! is_dir($directory) || is_link($directory)) {
            return null;
        }

        $iterator = new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            static function (SplFileInfo $member) use ($directory): bool {
                if ($member->getPath() !== $directory || $member->isLink()) {
                    return true;
                }

                return match ($member->getFilename()) {
                    self::GIT_METADATA => ! $member->isDir() && ! $member->isFile(),
                    self::MARKER => ! $member->isFile(),
                    default => true,
                };
            },
        );
        $members = 0;
        $total = 0;
        $fileDigests = [];

        /** @var SplFileInfo $member */
        foreach (new RecursiveIteratorIterator($iterator, RecursiveIteratorIterator::SELF_FIRST) as $member) {
            $path = str_replace(DIRECTORY_SEPARATOR, '/', substr($member->getPathname(), strlen($directory) + 1));
            if (++$members > self::MAX_MEMBERS || strlen($path) > self::MAX_PATH_BYTES || str_contains($path, '\\') || $member->isLink()) {
                return null;
            }

            if ($member->isDir()) {
                continue;
            }

            if (! $member->isFile()) {
                return null;
            }

            $size = $member->getSize();
            if ($size === false || $size > self::MAX_CONTENT_BYTES - $total) {
                return null;
            }

            $total += $size;
            $digest = $this->fileDigest($member->getPathname(), $size);
            if ($digest === null) {
                return null;
            }

            $fileDigests[$path] = $digest;
        }

        ksort($fileDigests, SORT_STRING);
        $hash = hash_init('sha256');
        hash_update($hash, "capell-release-content-v2\0");
        foreach ($fileDigests as $path => $digest) {
            $path = (string) $path;
            hash_update($hash, pack('N', strlen($path)) . $path . $digest);
        }

        return hash_final($hash);
    }

    private function fileDigest(string $path, int $size): ?string
    {
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            return null;
        }

        try {
            $hash = hash_init('sha256');

            return hash_update_stream($hash, $stream, $size + 1) === $size ? hash_final($hash, true) : null;
        } finally {
            fclose($stream);
        }
    }
}

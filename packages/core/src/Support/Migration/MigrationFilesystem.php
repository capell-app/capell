<?php

declare(strict_types=1);

namespace Capell\Core\Support\Migration;

final class MigrationFilesystem implements MigrationFilesystemInterface
{
    public function fileExists(string $path): bool
    {
        return file_exists($path);
    }

    public function glob(string $pattern): array
    {
        $matches = glob($pattern);

        return $matches === false ? [] : $matches;
    }

    public function isDir(string $path): bool
    {
        return is_dir($path);
    }

    public function isWritable(string $path): bool
    {
        return is_writable($path);
    }

    public function makeDir(string $path): void
    {
        if (! is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }

    public function copy(string $from, string $to): void
    {
        copy($from, $to);
    }

    public function delete(string $path): bool
    {
        return is_file($path) && unlink($path);
    }
}

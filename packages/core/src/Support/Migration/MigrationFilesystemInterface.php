<?php

declare(strict_types=1);

namespace Capell\Core\Support\Migration;

interface MigrationFilesystemInterface
{
    public function fileExists(string $path): bool;

    /**
     * @return list<string>
     */
    public function glob(string $pattern): array;

    public function isDir(string $path): bool;

    public function isWritable(string $path): bool;

    public function makeDir(string $path): void;

    public function copy(string $from, string $to): void;

    public function delete(string $path): bool;
}

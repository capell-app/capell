<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Feature\Commands\Fixtures;

use Capell\Core\Support\Migration\MigrationFilesystemInterface;

class FakeMigrationFilesystem implements MigrationFilesystemInterface
{
    public array $calls = [];

    public function copy(string $from, string $to): void
    {
        $this->calls[] = ['copy', $from, $to];
    }

    public function fileExists(string $path): bool
    {
        $this->calls[] = ['fileExists', $path];

        return true;
    }

    public function glob(string $pattern): array
    {
        $this->calls[] = ['glob', $pattern];

        return [];
    }

    public function isDir(string $path): bool
    {
        $this->calls[] = ['isDir', $path];

        return true;
    }

    public function isWritable(string $path): bool
    {
        $this->calls[] = ['isWritable', $path];

        return true;
    }

    public function makeDir(string $path): void
    {
        $this->calls[] = ['makeDir', $path];
    }

    public function delete(string $path): bool
    {
        $this->calls[] = ['delete', $path];

        return true;
    }
}

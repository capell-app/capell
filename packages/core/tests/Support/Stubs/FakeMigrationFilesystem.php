<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support\Stubs;

use Capell\Core\Support\Migration\MigrationFilesystemInterface;

class FakeMigrationFilesystem implements MigrationFilesystemInterface
{
    public array $calls = [];

    public function __construct(private array $overrides = []) {}

    public function fileExists(string $path): bool
    {
        $this->calls[] = ['fileExists', $path];

        return $this->overrides['fileExists'][$path] ?? false;
    }

    public function glob(string $pattern): array
    {
        $this->calls[] = ['glob', $pattern];

        return $this->overrides['glob'][$pattern] ?? [];
    }

    public function isDir(string $path): bool
    {
        $this->calls[] = ['isDir', $path];

        return $this->overrides['isDir'][$path] ?? true;
    }

    public function isWritable(string $path): bool
    {
        $this->calls[] = ['isWritable', $path];

        return $this->overrides['isWritable'][$path] ?? true;
    }

    public function makeDir(string $path): void
    {
        $this->calls[] = ['makeDir', $path];
        $this->overrides['isDir'][$path] = true;
    }

    public function copy(string $from, string $to): void
    {
        $this->calls[] = ['copy', $from, $to];
    }

    public function delete(string $path): bool
    {
        $this->calls[] = ['delete', $path];

        return $this->overrides['delete'][$path] ?? true;
    }
}

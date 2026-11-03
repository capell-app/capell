<?php

declare(strict_types=1);

namespace Capell\Marketplace\Support;

interface WorkspacePathInspector
{
    /**
     * @phpstan-impure Filesystem state can change between calls.
     *
     * @return array<string|int, int>|false
     */
    public function stat(string $path): array|false;

    public function realpath(string $path): string|false;

    public function effectiveUserId(): ?int;

    public function mkdir(string $path, int $mode): bool;

    /** @return list<string>|false */
    public function glob(string $pattern): array|false;

    public function modifiedAt(string $path): int|false;
}

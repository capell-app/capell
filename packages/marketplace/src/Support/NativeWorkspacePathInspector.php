<?php

declare(strict_types=1);

namespace Capell\Marketplace\Support;

use Override;

final class NativeWorkspacePathInspector implements WorkspacePathInspector
{
    #[Override]
    public function stat(string $path): array|false
    {
        clearstatcache(true, $path);

        return @lstat($path);
    }

    #[Override]
    public function realpath(string $path): string|false
    {
        return realpath($path);
    }

    #[Override]
    public function effectiveUserId(): ?int
    {
        return function_exists('posix_geteuid') ? posix_geteuid() : null;
    }

    #[Override]
    public function mkdir(string $path, int $mode): bool
    {
        return @mkdir($path, $mode);
    }

    #[Override]
    public function glob(string $pattern): array|false
    {
        return glob($pattern, GLOB_ONLYDIR);
    }

    #[Override]
    public function modifiedAt(string $path): int|false
    {
        return @filemtime($path);
    }
}

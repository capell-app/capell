<?php

declare(strict_types=1);

namespace Capell\Frontend\Tests\Support;

use Capell\Frontend\Contracts\StaticErrorPageStore;
use Illuminate\Support\Facades\File;
use Override;

/**
 * In-memory fake store backed by a temp dir on disk.
 */
class ResolveStaticErrorPageTestStore implements StaticErrorPageStore
{
    public string $directory;

    public function __construct()
    {
        $this->directory = storage_path('framework/testing/resolve-error-' . uniqid());
    }

    #[Override]
    public function exists(string $file): bool
    {
        return File::exists($this->fullPath($file));
    }

    #[Override]
    public function path(string $file): ?string
    {
        return $this->fullPath($file);
    }

    #[Override]
    public function put(string $file, string $contents): void
    {
        File::ensureDirectoryExists(dirname($this->fullPath($file)));
        File::put($this->fullPath($file), $contents);
    }

    private function fullPath(string $file): string
    {
        return $this->directory . '/' . ltrim($file, '/');
    }
}

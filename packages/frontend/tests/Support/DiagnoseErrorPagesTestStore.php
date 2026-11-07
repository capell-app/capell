<?php

declare(strict_types=1);

namespace Capell\Frontend\Tests\Support;

use Capell\Core\Support\PublicRead\PublicArtefactPermit;
use Capell\Frontend\Contracts\StaticErrorPageStore;
use Illuminate\Support\Facades\File;
use Override;

/**
 * Temp-dir backed fake store, mirroring the resolver's own test double.
 */
class DiagnoseErrorPagesTestStore implements StaticErrorPageStore
{
    use FencesTestArtefactStore;

    public string $directory;

    public function __construct()
    {
        $this->directory = storage_path('framework/testing/diagnose-error-' . bin2hex(random_bytes(8)));
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
    public function put(PublicArtefactPermit $permit, string $file, string $contents): void
    {
        File::ensureDirectoryExists(dirname($this->fullPath($file)));
        File::put($this->fullPath($file), $contents);
    }

    private function fullPath(string $file): string
    {
        return $this->directory . '/' . ltrim($file, '/');
    }
}

<?php

declare(strict_types=1);

namespace Capell\Frontend\Tests\Support;

use Capell\Frontend\Contracts\StaticErrorPageStore;
use Override;

/**
 * Records every file written by the static error page store, so a test can
 * assert that regeneration actually happened.
 */
class RecordingStaticErrorPageStore implements StaticErrorPageStore
{
    /** @var array<string, string> */
    public array $files = [];

    public int $writes = 0;

    #[Override]
    public function exists(string $file): bool
    {
        return array_key_exists($file, $this->files);
    }

    #[Override]
    public function path(string $file): ?string
    {
        return $this->exists($file) ? storage_path('framework/testing/' . str_replace('/', '-', $file)) : null;
    }

    #[Override]
    public function put(string $file, string $contents): void
    {
        $this->writes++;
        $this->files[$file] = $contents;
    }
}

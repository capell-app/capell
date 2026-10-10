<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

use Illuminate\Filesystem\Filesystem;
use Override;

final class BundleComposerFilesystem extends Filesystem
{
    /** @param array<string, string> $contents */
    public function __construct(public array $contents) {}

    #[Override]
    public function exists($path): bool
    {
        return array_key_exists((string) $path, $this->contents);
    }

    #[Override]
    public function get($path, $lock = false): string
    {
        return $this->contents[(string) $path];
    }

    #[Override]
    public function replace($path, $content, $mode = null): void
    {
        $this->contents[(string) $path] = (string) $content;
    }

    #[Override]
    public function delete($paths): bool
    {
        foreach ((array) $paths as $path) {
            unset($this->contents[(string) $path]);
        }

        return true;
    }
}

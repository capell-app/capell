<?php

declare(strict_types=1);

namespace Capell\Marketplace\Tests\Support;

use Illuminate\Filesystem\Filesystem;
use Override;

/**
 * A filesystem whose composer manifests live in memory, so a rollback test can
 * both seed and inspect them without writing to the real application root.
 */
final class InMemoryComposerFilesystem extends Filesystem
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

<?php

declare(strict_types=1);

namespace Capell\Tests\Support\Fakes;

use Illuminate\Filesystem\Filesystem;
use Override;

/** Simulates storage that cannot confirm deletion, including a misleading success. */
final class DeletionFailureFilesystem extends Filesystem
{
    public function __construct(
        private readonly string $path,
        private readonly bool $reportedSuccess = false,
        private readonly bool $remove = false,
    ) {}

    #[Override]
    public function delete(mixed $paths): bool
    {
        if (in_array($this->path, (array) $paths, true)) {
            if ($this->remove) {
                parent::delete($paths);
            }

            return $this->reportedSuccess;
        }

        return parent::delete($paths);
    }

    #[Override]
    public function deleteDirectory(mixed $directory, mixed $preserve = false): bool
    {
        if ($directory === $this->path) {
            if ($this->remove) {
                parent::deleteDirectory($directory, $preserve);
            }

            return $this->reportedSuccess;
        }

        return parent::deleteDirectory($directory, $preserve);
    }
}

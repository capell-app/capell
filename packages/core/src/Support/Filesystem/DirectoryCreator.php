<?php

declare(strict_types=1);

namespace Capell\Core\Support\Filesystem;

use RuntimeException;

final class DirectoryCreator
{
    public static function ensure(string $path, int $mode, string $failureMessage): void
    {
        throw_if(! is_dir($path) && ! mkdir($path, $mode, true) && ! is_dir($path), RuntimeException::class, $failureMessage);
    }
}

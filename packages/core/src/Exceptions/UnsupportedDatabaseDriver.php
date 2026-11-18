<?php

declare(strict_types=1);

namespace Capell\Core\Exceptions;

use RuntimeException;

final class UnsupportedDatabaseDriver extends RuntimeException
{
    public static function forDriver(string $driver): self
    {
        return new self(sprintf('Unsupported database driver [%s].', $driver));
    }
}

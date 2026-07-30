<?php

declare(strict_types=1);

namespace Capell\Core\Exceptions;

use RuntimeException;
use Throwable;

final class SchemaProbeFailedException extends RuntimeException
{
    public static function forTable(string $table, Throwable $previous): self
    {
        return new self(
            message: sprintf('Unable to determine whether database table [%s] exists.', $table),
            previous: $previous,
        );
    }

    public static function forColumn(string $table, string $column, Throwable $previous): self
    {
        return new self(
            message: sprintf('Unable to determine whether database column [%s.%s] exists.', $table, $column),
            previous: $previous,
        );
    }
}

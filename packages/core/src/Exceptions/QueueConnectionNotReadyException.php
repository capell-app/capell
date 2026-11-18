<?php

declare(strict_types=1);

namespace Capell\Core\Exceptions;

use RuntimeException;
use Throwable;

final class QueueConnectionNotReadyException extends RuntimeException
{
    public static function connectionMissing(string $connection): self
    {
        return new self((string) __('capell-core::queue.connection_missing', ['connection' => $connection]));
    }

    public static function syncNotSupported(string $connection): self
    {
        return new self((string) __('capell-core::queue.sync_not_supported', ['connection' => $connection]));
    }

    public static function databaseStorageUnavailable(string $connection, string $table, Throwable $previous): self
    {
        return new self(
            (string) __('capell-core::queue.database_storage_unavailable', ['connection' => $connection, 'table' => $table]),
            (int) $previous->getCode(),
            $previous,
        );
    }

    public static function databaseTableMissing(string $connection, string $table): self
    {
        return new self((string) __('capell-core::queue.database_table_missing', ['connection' => $connection, 'table' => $table]));
    }
}

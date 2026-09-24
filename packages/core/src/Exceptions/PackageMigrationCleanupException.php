<?php

declare(strict_types=1);

namespace Capell\Core\Exceptions;

use RuntimeException;

final class PackageMigrationCleanupException extends RuntimeException
{
    /** @param list<string> $blockedPaths */
    public function __construct(public readonly array $blockedPaths, string $retryCommand)
    {
        parent::__construct(__('capell-core::extensions.migration_cleanup_blocked', [
            'paths' => implode(', ', $blockedPaths),
            'command' => $retryCommand,
        ]));
    }
}

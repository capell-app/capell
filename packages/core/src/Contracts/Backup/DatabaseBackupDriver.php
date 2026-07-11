<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Backup;

interface DatabaseBackupDriver
{
    /**
     * @return non-empty-list<non-empty-string>
     */
    public function supportedDrivers(): array;

    public function create(string $connectionName, string $destinationPath): void;

    public function restore(string $connectionName, string $sourcePath, string $scratchDatabase): string;
}

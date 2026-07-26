<?php

declare(strict_types=1);

namespace Capell\Core\Support\Database\Provisioners;

use Capell\Core\Contracts\Database\DatabaseProvisioner;
use Illuminate\Support\Facades\File;

final class SqliteDatabaseProvisioner implements DatabaseProvisioner
{
    public function provision(string $connectionName, array $configuration): bool
    {
        $database = trim((string) ($configuration['database'] ?? ''));

        if ($database === '' || $database === ':memory:') {
            return false;
        }

        $path = $this->absolutePath($database);

        if (File::exists($path)) {
            return false;
        }

        File::ensureDirectoryExists(dirname($path));
        File::put($path, '');

        return true;
    }

    private function absolutePath(string $database): string
    {
        if (str_starts_with($database, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:[\\\\\\/]/', $database) === 1) {
            return $database;
        }

        return database_path($database);
    }
}

<?php

declare(strict_types=1);

namespace Capell\Core\Support\Backup\Drivers;

use Capell\Core\Contracts\Backup\DatabaseBackupDriver;
use Capell\Core\Support\Filesystem\DirectoryCreator;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;

final readonly class SqliteDatabaseBackupDriver implements DatabaseBackupDriver
{
    public function __construct(private Repository $config) {}

    public function supportedDrivers(): array
    {
        return ['sqlite'];
    }

    public function create(string $connectionName, string $destinationPath): void
    {
        $database = $this->databasePath($connectionName);

        if (! is_file($database)) {
            throw new RuntimeException(sprintf('SQLite database for connection [%s] does not exist.', $connectionName));
        }

        if (is_file($destinationPath) && filesize($destinationPath) === 0) {
            unlink($destinationPath);
        }

        throw_if(file_exists($destinationPath), RuntimeException::class, 'The SQLite backup destination already exists.');

        try {
            $pdo = new PDO('sqlite:' . $database);
            $escapedDestination = str_replace("'", "''", $destinationPath);
            $pdo->exec(sprintf("VACUUM INTO '%s'", $escapedDestination));
        } catch (PDOException) {
            throw new RuntimeException(sprintf('Unable to create the SQLite backup for connection [%s].', $connectionName));
        }

        chmod($destinationPath, 0600);
    }

    public function restore(string $connectionName, string $sourcePath, string $scratchDatabase): string
    {
        $this->databasePath($connectionName);

        throw_if(preg_match('/\A[A-Za-z]\w{2,62}\z/', $scratchDatabase) !== 1, InvalidArgumentException::class, 'SQLite restore requires a safe scratch database name.');

        throw_unless(is_file($sourcePath), RuntimeException::class, 'The SQLite backup artifact does not exist.');

        $scratchDirectory = (string) $this->config->get('backup.scratch.sqlite_directory', '');

        throw_if($scratchDirectory === '', RuntimeException::class, 'The SQLite scratch directory is not configured.');

        DirectoryCreator::ensure($scratchDirectory, 0700, 'Unable to create the SQLite scratch directory.');

        $destination = rtrim($scratchDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $scratchDatabase . '.sqlite';

        throw_if(file_exists($destination), RuntimeException::class, 'The SQLite scratch database already exists.');

        throw_unless(copy($sourcePath, $destination), RuntimeException::class, 'Unable to restore the SQLite backup into the scratch database.');

        chmod($destination, 0600);

        return $destination;
    }

    private function databasePath(string $connectionName): string
    {
        $database = $this->config->get(sprintf('database.connections.%s.database', $connectionName));

        if (! is_string($database) || $database === '' || $database === ':memory:') {
            throw new RuntimeException(sprintf('SQLite connection [%s] must use a persistent database file.', $connectionName));
        }

        return $database;
    }
}

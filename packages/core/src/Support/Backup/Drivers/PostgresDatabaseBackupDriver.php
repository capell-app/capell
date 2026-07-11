<?php

declare(strict_types=1);

namespace Capell\Core\Support\Backup\Drivers;

use Capell\Core\Contracts\Backup\DatabaseBackupDriver;
use Capell\Core\Support\Process\ProcessFactoryInterface;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

final readonly class PostgresDatabaseBackupDriver implements DatabaseBackupDriver
{
    public function __construct(
        private Repository $config,
        private ProcessFactoryInterface $processes,
    ) {}

    public function supportedDrivers(): array
    {
        return ['pgsql'];
    }

    public function create(string $connectionName, string $destinationPath): void
    {
        $connection = $this->connection($connectionName);
        $this->run([
            (string) $this->config->get('backup.binaries.pg_dump', 'pg_dump'),
            ...$this->connectionArguments($connection),
            '--format=plain',
            '--no-owner',
            '--no-privileges',
            '--file=' . $destinationPath,
            $connection['database'],
        ], $this->environment($connection), 'create', $connectionName);
    }

    public function restore(string $connectionName, string $sourcePath, string $scratchDatabase): string
    {
        if (preg_match('/\A[A-Za-z][A-Za-z0-9_]{2,62}\z/', $scratchDatabase) !== 1) {
            throw new InvalidArgumentException('PostgreSQL restore requires a safe scratch database name.');
        }

        if (! is_file($sourcePath)) {
            throw new RuntimeException('The PostgreSQL backup artifact does not exist.');
        }

        $connection = $this->connection($connectionName);
        $binary = (string) $this->config->get('backup.binaries.psql', 'psql');
        $arguments = $this->connectionArguments($connection);
        $environment = $this->environment($connection);

        $this->run([
            $binary,
            ...$arguments,
            '--dbname=postgres',
            '--command=CREATE DATABASE "' . $scratchDatabase . '"',
        ], $environment, 'create scratch database', $connectionName);
        $this->run([
            $binary,
            ...$arguments,
            '--set=ON_ERROR_STOP=1',
            '--dbname=' . $scratchDatabase,
            '--file=' . $sourcePath,
        ], $environment, 'restore', $connectionName);

        return $scratchDatabase;
    }

    /**
     * @return array{host: string, port: string, database: string, username: string, password: string}
     */
    private function connection(string $connectionName): array
    {
        $connection = $this->config->get(sprintf('database.connections.%s', $connectionName));

        if (! is_array($connection)) {
            throw new RuntimeException(sprintf('Database connection [%s] is not configured.', $connectionName));
        }

        foreach (['host', 'port', 'database', 'username'] as $key) {
            if (! is_scalar($connection[$key] ?? null) || (string) $connection[$key] === '') {
                throw new RuntimeException(sprintf('Database connection [%s] is missing [%s].', $connectionName, $key));
            }
        }

        return [
            'host' => (string) $connection['host'],
            'port' => (string) $connection['port'],
            'database' => (string) $connection['database'],
            'username' => (string) $connection['username'],
            'password' => is_scalar($connection['password'] ?? null) ? (string) $connection['password'] : '',
        ];
    }

    /**
     * @param  array{host: string, port: string, database: string, username: string, password: string}  $connection
     * @return list<string>
     */
    private function connectionArguments(array $connection): array
    {
        return [
            '--host=' . $connection['host'],
            '--port=' . $connection['port'],
            '--username=' . $connection['username'],
        ];
    }

    /**
     * @param  array{host: string, port: string, database: string, username: string, password: string}  $connection
     * @return array{PGPASSWORD: string}
     */
    private function environment(array $connection): array
    {
        return ['PGPASSWORD' => $connection['password']];
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $environment
     */
    private function run(array $command, array $environment, string $operation, string $connectionName): Process
    {
        try {
            $process = $this->processes->make($command, environment: $environment);
            $process->setTimeout(max(60, (int) $this->config->get('backup.process_timeout_seconds', 3600)));

            return $process->mustRun();
        } catch (Throwable) {
            throw new RuntimeException(sprintf('PostgreSQL backup %s failed for connection [%s].', $operation, $connectionName));
        }
    }
}

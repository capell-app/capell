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

final readonly class MySqlDatabaseBackupDriver implements DatabaseBackupDriver
{
    public function __construct(
        private Repository $config,
        private ProcessFactoryInterface $processes,
    ) {}

    public function supportedDrivers(): array
    {
        return ['mysql', 'mariadb'];
    }

    public function create(string $connectionName, string $destinationPath): void
    {
        $connection = $this->connection($connectionName);
        $command = [
            (string) $this->config->get('backup.binaries.mysqldump', 'mysqldump'),
            ...$this->connectionArguments($connection),
            '--single-transaction',
            '--quick',
            '--result-file=' . $destinationPath,
            $connection['database'],
        ];

        $this->run($command, $this->environment($connection), 'create', $connectionName);
    }

    public function restore(string $connectionName, string $sourcePath, string $scratchDatabase): string
    {
        if (preg_match('/\A[A-Za-z][A-Za-z0-9_]{2,62}\z/', $scratchDatabase) !== 1) {
            throw new InvalidArgumentException('MySQL restore requires a safe scratch database name.');
        }

        if (! is_file($sourcePath)) {
            throw new RuntimeException('The MySQL backup artifact does not exist.');
        }

        $connection = $this->connection($connectionName);
        $binary = (string) $this->config->get('backup.binaries.mysql', 'mysql');
        $arguments = $this->connectionArguments($connection);
        $environment = $this->environment($connection);

        $this->run([
            $binary,
            ...$arguments,
            '--execute=CREATE DATABASE `' . $scratchDatabase . '`',
        ], $environment, 'create scratch database', $connectionName);

        $input = fopen($sourcePath, 'rb');

        if ($input === false) {
            throw new RuntimeException('Unable to read the MySQL backup artifact.');
        }

        try {
            $process = $this->processes->make([$binary, ...$arguments, $scratchDatabase], environment: $environment);
            $process->setTimeout($this->processTimeout());
            $process->setInput($input);
            $process->mustRun();
        } catch (Throwable) {
            throw new RuntimeException(sprintf('MySQL restore failed for connection [%s].', $connectionName));
        } finally {
            fclose($input);
        }

        return $scratchDatabase;
    }

    /**
     * @return array{host: string, port: string, database: string, username: string, password: string, unix_socket?: string}
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
            'unix_socket' => is_string($connection['unix_socket'] ?? null) ? $connection['unix_socket'] : '',
        ];
    }

    /**
     * @param  array{host: string, port: string, database: string, username: string, password: string, unix_socket?: string}  $connection
     * @return list<string>
     */
    private function connectionArguments(array $connection): array
    {
        $arguments = [
            '--host=' . $connection['host'],
            '--port=' . $connection['port'],
            '--user=' . $connection['username'],
        ];

        if (($connection['unix_socket'] ?? '') !== '') {
            $arguments[] = '--socket=' . $connection['unix_socket'];
        }

        return $arguments;
    }

    /**
     * @param  array{host: string, port: string, database: string, username: string, password: string, unix_socket?: string}  $connection
     * @return array{MYSQL_PWD: string}
     */
    private function environment(array $connection): array
    {
        return ['MYSQL_PWD' => $connection['password']];
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $environment
     */
    private function run(array $command, array $environment, string $operation, string $connectionName): Process
    {
        try {
            $process = $this->processes->make($command, environment: $environment);
            $process->setTimeout($this->processTimeout());

            return $process->mustRun();
        } catch (Throwable) {
            throw new RuntimeException(sprintf('MySQL backup %s failed for connection [%s].', $operation, $connectionName));
        }
    }

    private function processTimeout(): int
    {
        return max(60, (int) $this->config->get('backup.process_timeout_seconds', 3600));
    }
}

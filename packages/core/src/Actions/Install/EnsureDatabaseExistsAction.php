<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Contracts\ProgressReporter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use PDO;
use RuntimeException;

final class EnsureDatabaseExistsAction
{
    use AsFake;
    use AsObject;

    public function handle(ProgressReporter $reporter): void
    {
        $connectionName = config('database.default');
        $config = config('database.connections.' . $connectionName);

        if (! is_string($connectionName) || ! is_array($config)) {
            return;
        }

        $driver = $config['driver'] ?? null;

        match ($driver) {
            'sqlite' => $this->ensureSqliteDatabase($config, $reporter),
            'mysql', 'mariadb' => $this->ensureMysqlDatabase($connectionName, $config, $reporter),
            'pgsql' => $this->ensurePostgresDatabase($connectionName, $config, $reporter),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function ensureSqliteDatabase(array $config, ProgressReporter $reporter): void
    {
        $database = (string) ($config['database'] ?? '');

        if ($database === '' || $database === ':memory:') {
            return;
        }

        $path = $this->absoluteSqlitePath($database);

        if (File::exists($path)) {
            return;
        }

        File::ensureDirectoryExists(dirname($path));
        File::put($path, '');

        $reporter->report('✓ SQLite database created');
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function ensureMysqlDatabase(string $connectionName, array $config, ProgressReporter $reporter): void
    {
        $database = trim((string) ($config['database'] ?? ''));

        if ($database === '') {
            return;
        }

        $pdo = $this->makeMysqlServerPdo($config);
        $sql = 'CREATE DATABASE IF NOT EXISTS ' . $this->quoteMysqlIdentifier($database);

        $charset = $config['charset'] ?? null;
        if (is_string($charset) && $this->isSimpleIdentifier($charset)) {
            $sql .= ' CHARACTER SET ' . $charset;
        }

        $collation = $config['collation'] ?? null;
        if (is_string($collation) && $this->isSimpleIdentifier($collation)) {
            $sql .= ' COLLATE ' . $collation;
        }

        $pdo->exec($sql);
        $this->refreshConnection($connectionName);

        $reporter->report('✓ Database is ready');
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function ensurePostgresDatabase(string $connectionName, array $config, ProgressReporter $reporter): void
    {
        $database = trim((string) ($config['database'] ?? ''));

        if ($database === '') {
            return;
        }

        $pdo = $this->makePostgresServerPdo($config);
        $statement = $pdo->prepare('select 1 from pg_database where datname = ?');
        $statement->execute([$database]);

        if ($statement->fetchColumn() === false) {
            $pdo->exec('CREATE DATABASE ' . $this->quotePostgresIdentifier($database));
        }

        $this->refreshConnection($connectionName);

        $reporter->report('✓ Database is ready');
    }

    private function absoluteSqlitePath(string $database): string
    {
        if ($this->isAbsolutePath($database)) {
            return $database;
        }

        return database_path($database);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function makeMysqlServerPdo(array $config): PDO
    {
        $socket = (string) ($config['unix_socket'] ?? '');

        if ($socket !== '') {
            $dsn = 'mysql:unix_socket=' . $socket;
        } else {
            $host = $this->firstHost($config['host'] ?? '127.0.0.1');
            $port = (string) ($config['port'] ?? '3306');
            $dsn = sprintf('mysql:host=%s;port=%s', $host, $port);
        }

        $charset = $config['charset'] ?? null;
        if (is_string($charset) && $this->isSimpleIdentifier($charset)) {
            $dsn .= ';charset=' . $charset;
        }

        return $this->makePdo($dsn, $config);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function makePostgresServerPdo(array $config): PDO
    {
        $host = $this->firstHost($config['host'] ?? '127.0.0.1');
        $port = (string) ($config['port'] ?? '5432');
        $database = (string) ($config['maintenance_database'] ?? 'postgres');

        return $this->makePdo(sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $database), $config);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function makePdo(string $dsn, array $config): PDO
    {
        $options = $config['options'] ?? [];

        if (! is_array($options)) {
            $options = [];
        }

        $pdo = new PDO(
            $dsn,
            (string) ($config['username'] ?? ''),
            (string) ($config['password'] ?? ''),
            $options,
        );

        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }

    private function refreshConnection(string $connectionName): void
    {
        DB::purge($connectionName);
        DB::reconnect($connectionName);
    }

    private function firstHost(mixed $host): string
    {
        if (is_array($host)) {
            $firstHost = reset($host);

            return is_string($firstHost) && $firstHost !== '' ? $firstHost : '127.0.0.1';
        }

        return is_string($host) && $host !== '' ? $host : '127.0.0.1';
    }

    private function quoteMysqlIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    private function quotePostgresIdentifier(string $identifier): string
    {
        throw_if(str_contains($identifier, "\0"), RuntimeException::class, 'Database name cannot contain null bytes.');

        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    private function isSimpleIdentifier(string $value): bool
    {
        return preg_match('/^\w+$/', $value) === 1;
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1;
    }
}

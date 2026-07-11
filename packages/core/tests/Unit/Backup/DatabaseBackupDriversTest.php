<?php

declare(strict_types=1);

use Capell\Core\Support\Backup\BackupTemporaryFiles;
use Capell\Core\Support\Backup\Drivers\MySqlDatabaseBackupDriver;
use Capell\Core\Support\Backup\Drivers\PostgresDatabaseBackupDriver;
use Capell\Core\Support\Backup\Drivers\SqliteDatabaseBackupDriver;
use Capell\Core\Support\Process\ProcessFactoryInterface;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->temporaryDirectory = sys_get_temp_dir() . '/capell-backup-driver-' . bin2hex(random_bytes(6));
    mkdir($this->temporaryDirectory, 0700, true);
});

afterEach(function (): void {
    if (is_dir($this->temporaryDirectory)) {
        collect(glob($this->temporaryDirectory . '/*') ?: [])->each(static fn (string $path): bool => unlink($path));
        rmdir($this->temporaryDirectory);
    }
});

it('copies sqlite databases and restores only beneath the scratch directory', function (): void {
    $source = $this->temporaryDirectory . '/live.sqlite';
    $dump = $this->temporaryDirectory . '/database.sqlite';
    $database = new PDO('sqlite:' . $source);
    $database->exec('CREATE TABLE examples (value TEXT NOT NULL)');
    $database->exec("INSERT INTO examples (value) VALUES ('sqlite-backup-content')");
    config([
        'database.connections.backup_test' => ['driver' => 'sqlite', 'database' => $source],
        'backup.scratch.sqlite_directory' => $this->temporaryDirectory . '/scratch',
    ]);

    $driver = new SqliteDatabaseBackupDriver(config());
    $driver->create('backup_test', $dump);
    $restored = $driver->restore('backup_test', $dump, 'capell_restore_test');

    expect(backupSqliteValue($dump))->toBe('sqlite-backup-content')
        ->and($restored)->toBe($this->temporaryDirectory . '/scratch/capell_restore_test.sqlite')
        ->and(backupSqliteValue($restored))->toBe('sqlite-backup-content')
        ->and(fn (): string => $driver->restore('backup_test', $dump, '../live.sqlite'))
        ->toThrow(InvalidArgumentException::class, 'safe scratch database name');

    unlink($restored);
    rmdir(dirname($restored));
});

it('builds mysql backup and restore processes without exposing passwords in arguments', function (): void {
    $factory = new RecordingBackupProcessFactory;
    $source = $this->temporaryDirectory . '/database.sql';
    file_put_contents($source, 'CREATE TABLE example (id INT);');
    config([
        'database.connections.backup_test' => [
            'driver' => 'mysql',
            'host' => 'database.internal',
            'port' => 3307,
            'database' => 'capell_live',
            'username' => 'capell',
            'password' => 'super-secret',
        ],
        'backup.binaries.mysqldump' => '/usr/bin/mysqldump',
        'backup.binaries.mysql' => '/usr/bin/mysql',
    ]);

    $driver = new MySqlDatabaseBackupDriver(config(), $factory);
    $driver->create('backup_test', $this->temporaryDirectory . '/dump.sql');
    $restored = $driver->restore('backup_test', $source, 'capell_restore_test');

    expect($restored)->toBe('capell_restore_test')
        ->and($factory->commands)->toHaveCount(3)
        ->and($factory->commands[0])->toContain('/usr/bin/mysqldump', '--result-file=' . $this->temporaryDirectory . '/dump.sql', 'capell_live')
        ->and($factory->commands[1])->toContain('--execute=CREATE DATABASE `capell_restore_test`')
        ->and($factory->commands[2])->toContain('capell_restore_test')
        ->and($factory->environments)->each->toMatchArray(['MYSQL_PWD' => 'super-secret'])
        ->and(json_encode($factory->commands, JSON_THROW_ON_ERROR))->not->toContain('super-secret');
});

it('builds postgres backup and restore processes without exposing passwords in arguments', function (): void {
    $factory = new RecordingBackupProcessFactory;
    $source = $this->temporaryDirectory . '/database.dump';
    file_put_contents($source, 'postgres-backup');
    config([
        'database.connections.backup_test' => [
            'driver' => 'pgsql',
            'host' => 'postgres.internal',
            'port' => 5433,
            'database' => 'capell_live',
            'username' => 'capell',
            'password' => 'super-secret',
        ],
        'backup.binaries.pg_dump' => '/usr/bin/pg_dump',
        'backup.binaries.psql' => '/usr/bin/psql',
    ]);

    $driver = new PostgresDatabaseBackupDriver(config(), $factory);
    $driver->create('backup_test', $this->temporaryDirectory . '/dump.sql');
    $restored = $driver->restore('backup_test', $source, 'capell_restore_test');

    expect($restored)->toBe('capell_restore_test')
        ->and($factory->commands)->toHaveCount(3)
        ->and($factory->commands[0])->toContain('/usr/bin/pg_dump', '--file=' . $this->temporaryDirectory . '/dump.sql', 'capell_live')
        ->and($factory->commands[1])->toContain('--command=CREATE DATABASE "capell_restore_test"')
        ->and($factory->commands[2])->toContain('--file=' . $source, '--dbname=capell_restore_test')
        ->and($factory->environments)->each->toMatchArray(['PGPASSWORD' => 'super-secret'])
        ->and(json_encode($factory->commands, JSON_THROW_ON_ERROR))->not->toContain('super-secret');
});

it('removes owned temporary files when the scope is destroyed', function (): void {
    $files = new BackupTemporaryFiles($this->temporaryDirectory);
    $path = $files->create('database-');
    file_put_contents($path, 'temporary');

    $files->cleanup();

    expect(file_exists($path))->toBeFalse();
});

it('reports failed process operations without leaking connection secrets', function (): void {
    $factory = new RecordingBackupProcessFactory(fail: true);
    config([
        'database.connections.backup_test' => [
            'driver' => 'mysql',
            'host' => 'database.internal',
            'port' => 3306,
            'database' => 'capell_live',
            'username' => 'capell',
            'password' => 'never-print-this',
        ],
    ]);

    try {
        (new MySqlDatabaseBackupDriver(config(), $factory))->create('backup_test', $this->temporaryDirectory . '/dump.sql');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('MySQL backup create failed for connection [backup_test].')
            ->and($exception->getPrevious())->toBeNull()
            ->and($exception->getTraceAsString())->not->toContain('never-print-this');

        return;
    }

    $this->fail('Expected the process failure to be reported.');
});

final class RecordingBackupProcessFactory implements ProcessFactoryInterface
{
    /** @var list<list<string>|string> */
    public array $commands = [];

    /** @var list<array<string, string>> */
    public array $environments = [];

    public function __construct(private readonly bool $fail = false) {}

    public function make(array|string $command, ?string $cwd = null, ?array $environment = null): Process
    {
        $this->commands[] = $command;
        $this->environments[] = $environment ?? [];

        return new Process($this->fail
            ? ['/definitely-missing-capell-backup-command']
            : [PHP_BINARY, '-r', 'return;']);
    }
}

function backupSqliteValue(string $databasePath): string
{
    $statement = (new PDO('sqlite:' . $databasePath))->query('SELECT value FROM examples');

    if ($statement === false) {
        throw new RuntimeException('Unable to read the SQLite backup fixture.');
    }

    $value = $statement->fetchColumn();

    if (! is_string($value)) {
        throw new RuntimeException('The SQLite backup fixture did not contain a value.');
    }

    return $value;
}

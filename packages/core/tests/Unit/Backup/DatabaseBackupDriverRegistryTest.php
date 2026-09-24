<?php

declare(strict_types=1);

use Capell\Core\Contracts\Backup\DatabaseBackupDriver;
use Capell\Core\Data\Backup\BackupArtifactData;
use Capell\Core\Data\Backup\BackupHealthReportData;
use Capell\Core\Data\Backup\BackupManifestData;
use Capell\Core\Support\Backup\DatabaseBackupDriverRegistry;
use Capell\Core\Support\Backup\Drivers\MySqlDatabaseBackupDriver;
use Capell\Core\Support\Backup\Drivers\PostgresDatabaseBackupDriver;
use Capell\Core\Support\Backup\Drivers\SqliteDatabaseBackupDriver;
use Illuminate\Container\Container;
use Illuminate\Contracts\Container\BindingResolutionException;

it('refuses to autowire a database backup registry without configured drivers', function (): void {
    expect(fn (): DatabaseBackupDriverRegistry => new Container()->make(DatabaseBackupDriverRegistry::class))
        ->toThrow(BindingResolutionException::class, '$drivers');
});

it('rejects an empty database backup driver array during construction', function (): void {
    expect(fn (): DatabaseBackupDriverRegistry => new DatabaseBackupDriverRegistry([]))
        ->toThrow(LogicException::class, 'DatabaseBackupDriverRegistry requires at least one registered driver.');
});

it('rejects an empty database backup driver iterator during construction', function (): void {
    expect(fn (): DatabaseBackupDriverRegistry => new DatabaseBackupDriverRegistry(new ArrayIterator))
        ->toThrow(LogicException::class, 'DatabaseBackupDriverRegistry requires at least one registered driver.');
});

it('rejects backup driver collections that register no drivers', function (): void {
    $driver = Mockery::mock(DatabaseBackupDriver::class);
    $driver->shouldReceive('supportedDrivers')->once()->andReturn([]);

    expect(fn (): DatabaseBackupDriverRegistry => new DatabaseBackupDriverRegistry([$driver]))
        ->toThrow(LogicException::class, 'DatabaseBackupDriverRegistry requires at least one registered driver.');
});

it('accepts a populated database backup driver iterator', function (): void {
    $driver = backupDriverForRegistryTest(['sqlite']);
    $registry = new DatabaseBackupDriverRegistry(new ArrayIterator([$driver]));

    expect($registry->for('sqlite'))->toBe($driver);
});

it('rebuilds populated backup registries and their drivers for each operation', function (): void {
    $registry = resolve(DatabaseBackupDriverRegistry::class);

    expect(resolve(DatabaseBackupDriverRegistry::class))->toBe($registry);

    app()->forgetScopedInstances();
    $resolved = resolve(DatabaseBackupDriverRegistry::class);

    expect($resolved)->not->toBe($registry)
        ->and($resolved->for('mysql'))->toBeInstanceOf(MySqlDatabaseBackupDriver::class)
        ->and($resolved->for('mysql'))->not->toBe($registry->for('mysql'))
        ->and($resolved->for('mariadb'))->toBe($resolved->for('mysql'))
        ->and($resolved->for('sqlite'))->toBeInstanceOf(SqliteDatabaseBackupDriver::class)
        ->and($resolved->for('sqlite'))->not->toBe($registry->for('sqlite'))
        ->and($resolved->for('pgsql'))->toBeInstanceOf(PostgresDatabaseBackupDriver::class)
        ->and($resolved->for('pgsql'))->not->toBe($registry->for('pgsql'));
});

it('reapplies backup driver registrations from resolving callbacks in each operation', function (): void {
    $driver = backupDriverForRegistryTest(['custom']);
    app()->resolving(DatabaseBackupDriverRegistry::class, function (DatabaseBackupDriverRegistry $registry) use ($driver): void {
        $registry->register($driver);
    });
    $registry = resolve(DatabaseBackupDriverRegistry::class);

    expect($registry->for('custom'))->toBe($driver);

    app()->forgetScopedInstances();
    $resolved = resolve(DatabaseBackupDriverRegistry::class);

    expect($resolved->for('custom'))->toBe($driver)
        ->and($resolved->for('sqlite'))->toBeInstanceOf(SqliteDatabaseBackupDriver::class)
        ->and($resolved)->not->toBe($registry);
});

it('resolves registered database backup drivers by connection driver', function (): void {
    $driver = new class implements DatabaseBackupDriver
    {
        public function supportedDrivers(): array
        {
            return ['mysql', 'mariadb'];
        }

        public function create(string $connectionName, string $destinationPath): void {}

        public function restore(string $connectionName, string $sourcePath, string $scratchDatabase): string
        {
            return $scratchDatabase;
        }
    };

    $registry = new DatabaseBackupDriverRegistry([$driver]);

    expect($registry->for('mysql'))->toBe($driver)
        ->and($registry->for('mariadb'))->toBe($driver);
});

it('rejects unsupported and duplicate database drivers clearly', function (): void {
    $first = backupDriverForRegistryTest(['sqlite']);
    $duplicate = backupDriverForRegistryTest(['sqlite']);

    expect(fn (): DatabaseBackupDriver => new DatabaseBackupDriverRegistry([$first])->for('sqlsrv'))
        ->toThrow(InvalidArgumentException::class, 'Unsupported database backup driver [sqlsrv].')
        ->and(fn (): DatabaseBackupDriverRegistry => new DatabaseBackupDriverRegistry([$first, $duplicate]))
        ->toThrow(LogicException::class, 'Database backup driver [sqlite] is already registered.');
});

it('serializes backup metadata without credentials absolute paths or content', function (): void {
    $database = new BackupArtifactData(
        kind: 'database',
        path: '2026-07-10T170000Z-a1b2/database.sql.gz',
        bytes: 512,
        sha256: str_repeat('a', 64),
    );
    $media = new BackupArtifactData(
        kind: 'media',
        path: '2026-07-10T170000Z-a1b2/media/public/photo.jpg',
        bytes: 128,
        sha256: str_repeat('b', 64),
        sourceDisk: 'public',
        sourcePath: 'photo.jpg',
    );
    $manifest = new BackupManifestData(
        formatVersion: 1,
        snapshotId: '2026-07-10T170000Z-a1b2',
        createdAt: '2026-07-10T17:00:00+00:00',
        databaseDriver: 'sqlite',
        connectionName: 'default',
        database: $database,
        media: [$media],
    );
    $health = new BackupHealthReportData(
        status: 'healthy',
        checkedAt: '2026-07-10T17:05:00+00:00',
        snapshotCount: 1,
        newestSnapshotAt: '2026-07-10T17:00:00+00:00',
        checks: [['name' => 'freshness', 'passed' => true, 'message' => 'Latest snapshot is fresh.']],
    );

    expect($manifest->toArray())->toBe([
        'format_version' => 1,
        'snapshot_id' => '2026-07-10T170000Z-a1b2',
        'created_at' => '2026-07-10T17:00:00+00:00',
        'database_driver' => 'sqlite',
        'connection_name' => 'default',
        'database' => $database->toArray(),
        'media' => [$media->toArray()],
        'media_file_count' => 1,
        'media_bytes' => 128,
    ])->and($health->toArray())->toBe([
        'status' => 'healthy',
        'checked_at' => '2026-07-10T17:05:00+00:00',
        'snapshot_count' => 1,
        'newest_snapshot_at' => '2026-07-10T17:00:00+00:00',
        'checks' => [['name' => 'freshness', 'passed' => true, 'message' => 'Latest snapshot is fresh.']],
    ])->and(json_encode($manifest->toArray(), JSON_THROW_ON_ERROR))
        ->not->toContain('password', '/Users/', 'secret', 'contents');
});

/**
 * @param  non-empty-list<non-empty-string>  $supportedDrivers
 */
function backupDriverForRegistryTest(array $supportedDrivers): DatabaseBackupDriver
{
    return new readonly class($supportedDrivers) implements DatabaseBackupDriver
    {
        /**
         * @param  non-empty-list<non-empty-string>  $supportedDrivers
         */
        public function __construct(private array $supportedDrivers) {}

        public function supportedDrivers(): array
        {
            return $this->supportedDrivers;
        }

        public function create(string $connectionName, string $destinationPath): void {}

        public function restore(string $connectionName, string $sourcePath, string $scratchDatabase): string
        {
            return $scratchDatabase;
        }
    };
}

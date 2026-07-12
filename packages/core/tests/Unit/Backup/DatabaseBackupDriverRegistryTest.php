<?php

declare(strict_types=1);

use Capell\Core\Contracts\Backup\DatabaseBackupDriver;
use Capell\Core\Data\Backup\BackupArtifactData;
use Capell\Core\Data\Backup\BackupHealthReportData;
use Capell\Core\Data\Backup\BackupManifestData;
use Capell\Core\Support\Backup\DatabaseBackupDriverRegistry;

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

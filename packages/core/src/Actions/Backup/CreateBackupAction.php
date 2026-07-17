<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Backup;

use Capell\Core\Data\Backup\BackupArtifactData;
use Capell\Core\Data\Backup\BackupManifestData;
use Capell\Core\Support\Backup\BackupArtifactStore;
use Capell\Core\Support\Backup\BackupTemporaryFiles;
use Capell\Core\Support\Backup\DatabaseBackupDriverRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\FilesystemManager;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;
use Throwable;

/**
 * @method static BackupManifestData run(bool $databaseOnly = false)
 */
final class CreateBackupAction
{
    use AsFake;
    use AsObject;

    public function __construct(
        private readonly Repository $config,
        private readonly FilesystemManager $filesystems,
        private readonly DatabaseBackupDriverRegistry $drivers,
        private readonly BackupArtifactStore $store,
    ) {}

    public function handle(bool $databaseOnly = false): BackupManifestData
    {
        $this->store->assertAvailable();
        $connectionName = $this->connectionName();
        $databaseDriver = $this->databaseDriver($connectionName);
        $mediaDisks = $databaseOnly ? [] : $this->mediaDisks();

        throw_if(in_array($this->store->diskName(), $mediaDisks, true), RuntimeException::class, 'Backup storage cannot also be a media source.');

        $now = CarbonImmutable::now('UTC');
        $snapshotId = $now->format('Ymd\THis\Z') . '-' . bin2hex(random_bytes(6));
        $temporaryFiles = new BackupTemporaryFiles;

        try {
            $databaseArtifact = $this->createDatabaseArtifact(
                snapshotId: $snapshotId,
                connectionName: $connectionName,
                databaseDriver: $databaseDriver,
                temporaryFiles: $temporaryFiles,
            );
            $mediaArtifacts = $this->createMediaArtifacts($snapshotId, $mediaDisks, $temporaryFiles);
            $manifest = new BackupManifestData(
                formatVersion: 1,
                snapshotId: $snapshotId,
                createdAt: $now->toAtomString(),
                databaseDriver: $databaseDriver,
                connectionName: $connectionName,
                database: $databaseArtifact,
                media: $mediaArtifacts,
            );

            $this->store->putManifest($snapshotId, $manifest->toArray());

            return $manifest;
        } catch (Throwable $throwable) {
            try {
                $this->store->deleteSnapshot($snapshotId);
            } catch (Throwable) {
                // Preserve the operation failure; an incomplete prefix has no manifest and is never restorable.
            }

            throw $throwable;
        } finally {
            $temporaryFiles->cleanup();
        }
    }

    private function createDatabaseArtifact(
        string $snapshotId,
        string $connectionName,
        string $databaseDriver,
        BackupTemporaryFiles $temporaryFiles,
    ): BackupArtifactData {
        $databasePath = $temporaryFiles->create('capell-database-');
        $compressedPath = $temporaryFiles->create('capell-database-gzip-');
        $this->drivers->for($databaseDriver)->create($connectionName, $databasePath);
        $this->gzip($databasePath, $compressedPath);
        $storedPath = $this->store->snapshotPath($snapshotId, 'database.sql.gz');
        $this->store->putLocalFile($storedPath, $compressedPath);

        return $this->artifact('database', $storedPath, $compressedPath);
    }

    /**
     * @param  list<string>  $mediaDisks
     * @return list<BackupArtifactData>
     */
    private function createMediaArtifacts(string $snapshotId, array $mediaDisks, BackupTemporaryFiles $temporaryFiles): array
    {
        $artifacts = [];

        foreach ($mediaDisks as $diskName) {
            $disk = $this->filesystems->disk($diskName);
            $diskPath = preg_replace('/[^A-Za-z0-9_.-]/', '_', $diskName) ?: 'media';

            foreach ($disk->allFiles() as $sourcePath) {
                $sourcePath = str_replace('\\', '/', $sourcePath);
                $temporaryPath = $temporaryFiles->create('capell-media-');
                $source = $disk->readStream($sourcePath);

                if ($source === null) {
                    throw new RuntimeException(sprintf('Unable to read media artifact [%s:%s].', $diskName, $sourcePath));
                }

                try {
                    $this->copyStreamToFile($source, $temporaryPath);
                } finally {
                    fclose($source);
                }

                $storedPath = $this->store->snapshotPath($snapshotId, 'media/' . $diskPath . '/' . $sourcePath);
                $this->store->putLocalFile($storedPath, $temporaryPath);
                $artifacts[] = $this->artifact(
                    kind: 'media',
                    storedPath: $storedPath,
                    localPath: $temporaryPath,
                    sourceDisk: $diskName,
                    sourcePath: $sourcePath,
                );
            }
        }

        return $artifacts;
    }

    private function gzip(string $sourcePath, string $destinationPath): void
    {
        $source = fopen($sourcePath, 'rb');
        $destination = gzopen($destinationPath, 'wb9');

        if ($source === false || $destination === false) {
            if (is_resource($source)) {
                fclose($source);
            }

            if (is_resource($destination)) {
                gzclose($destination);
            }

            throw new RuntimeException('Unable to compress the database backup.');
        }

        try {
            throw_if(stream_copy_to_stream($source, $destination) === false, RuntimeException::class, 'Unable to compress the database backup.');
        } finally {
            fclose($source);
            gzclose($destination);
        }
    }

    /** @param resource $source */
    private function copyStreamToFile(mixed $source, string $destinationPath): void
    {
        $destination = fopen($destinationPath, 'wb');

        throw_if($destination === false, RuntimeException::class, 'Unable to create a temporary media artifact.');

        try {
            throw_if(stream_copy_to_stream($source, $destination) === false, RuntimeException::class, 'Unable to copy a media artifact.');
        } finally {
            fclose($destination);
        }
    }

    private function artifact(
        string $kind,
        string $storedPath,
        string $localPath,
        ?string $sourceDisk = null,
        ?string $sourcePath = null,
    ): BackupArtifactData {
        $bytes = filesize($localPath);
        $sha256 = hash_file('sha256', $localPath);

        throw_if($bytes === false || $sha256 === false, RuntimeException::class, 'Unable to inspect a backup artifact.');

        return new BackupArtifactData($kind, $storedPath, $bytes, $sha256, $sourceDisk, $sourcePath);
    }

    private function connectionName(): string
    {
        $connection = $this->config->get('backup.connection') ?? $this->config->get('database.default');

        throw_if(! is_string($connection) || $connection === '', RuntimeException::class, 'The backup database connection is not configured.');

        return $connection;
    }

    private function databaseDriver(string $connectionName): string
    {
        $driver = $this->config->get(sprintf('database.connections.%s.driver', $connectionName));

        if (! is_string($driver) || $driver === '') {
            throw new RuntimeException(sprintf('Database connection [%s] has no driver.', $connectionName));
        }

        return strtolower($driver);
    }

    /** @return list<string> */
    private function mediaDisks(): array
    {
        $disks = $this->config->get('backup.media_disks', []);
        throw_unless(is_array($disks), RuntimeException::class, 'Backup media disks must be an array.');

        return array_values(array_unique(array_filter($disks, static fn (mixed $disk): bool => is_string($disk) && $disk !== '')));
    }
}

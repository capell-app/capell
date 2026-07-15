<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Backup;

use Capell\Core\Data\Backup\BackupArtifactData;
use Capell\Core\Data\Backup\BackupManifestData;
use Capell\Core\Data\Backup\BackupRestoreResultData;
use Capell\Core\Support\Backup\BackupArtifactStore;
use Capell\Core\Support\Backup\BackupTemporaryFiles;
use Capell\Core\Support\Backup\DatabaseBackupDriverRegistry;
use Capell\Core\Support\Process\ArtisanProcessEnvironment;
use Capell\Core\Support\Process\ProcessFactoryInterface;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\FilesystemManager;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;
use Throwable;

/**
 * @method static BackupRestoreResultData run(string $snapshotId, string $scratchDatabase, ?string $mediaDisk = null, ?string $mediaPrefix = null)
 */
final class RestoreBackupAction
{
    use AsObject;

    public function __construct(
        private readonly Repository $config,
        private readonly FilesystemManager $filesystems,
        private readonly DatabaseBackupDriverRegistry $drivers,
        private readonly BackupArtifactStore $store,
        private readonly ProcessFactoryInterface $processes,
    ) {}

    public function handle(
        string $snapshotId,
        string $scratchDatabase,
        ?string $mediaDisk = null,
        ?string $mediaPrefix = null,
    ): BackupRestoreResultData {
        $this->store->assertAvailable();
        $manifest = BackupManifestData::fromManifestArray($this->store->manifest($snapshotId));

        throw_if($manifest->snapshotId !== $snapshotId, RuntimeException::class, 'Backup manifest identity does not match the requested snapshot.');

        $this->assertScratchDatabase($manifest, $scratchDatabase);
        $this->assertMediaTarget($manifest, $mediaDisk, $mediaPrefix);
        $this->assertArtifacts($manifest);
        $temporaryFiles = new BackupTemporaryFiles;

        try {
            $compressedDatabase = $temporaryFiles->create('capell-restore-gzip-');
            $databaseArtifact = $temporaryFiles->create('capell-restore-database-');
            $this->store->download($manifest->database->path, $compressedDatabase);
            $this->assertLocalArtifact($manifest->database, $compressedDatabase);
            $this->gunzip($compressedDatabase, $databaseArtifact);
            $restoredDatabase = $this->drivers->for($manifest->databaseDriver)->restore(
                $manifest->connectionName,
                $databaseArtifact,
                $scratchDatabase,
            );
            $mediaFiles = $this->restoreMedia($manifest, $mediaDisk, $mediaPrefix, $temporaryFiles);
            $doctorStatus = $this->runDoctor($manifest->connectionName, $restoredDatabase);

            return new BackupRestoreResultData($snapshotId, $restoredDatabase, $mediaFiles, $doctorStatus);
        } finally {
            $temporaryFiles->cleanup();
        }
    }

    private function assertScratchDatabase(BackupManifestData $manifest, string $scratchDatabase): void
    {
        $prefix = $this->config->get('backup.scratch.database_prefix', 'capell_restore_');

        throw_if(! is_string($prefix) || $prefix === '' || ! str_starts_with($scratchDatabase, $prefix)
            || preg_match('/\A[A-Za-z]\w{2,62}\z/', $scratchDatabase) !== 1, InvalidArgumentException::class, 'Restore requires a safe scratch database name with the configured prefix.');

        $liveDatabase = $this->config->get(sprintf('database.connections.%s.database', $manifest->connectionName));

        throw_if(! is_string($liveDatabase) || $liveDatabase === '', RuntimeException::class, 'The live database target is not configured.');

        if ($manifest->databaseDriver === 'sqlite') {
            $scratchDirectory = (string) $this->config->get('backup.scratch.sqlite_directory', '');
            $scratchPath = rtrim($scratchDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $scratchDatabase . '.sqlite';

            throw_if($scratchDirectory === '' || $this->samePath($liveDatabase, $scratchPath), InvalidArgumentException::class, 'Scratch restore database must differ from the live database.');

            return;
        }

        throw_if($liveDatabase === $scratchDatabase, InvalidArgumentException::class, 'Scratch restore database must differ from the live database.');
    }

    private function assertMediaTarget(BackupManifestData $manifest, ?string $mediaDisk, ?string $mediaPrefix): void
    {
        if ($manifest->media === []) {
            return;
        }

        throw_if($mediaDisk === null || $mediaDisk === '' || $mediaPrefix === null || $mediaPrefix === '', InvalidArgumentException::class, 'Media restore requires a scratch media disk and non-empty prefix.');

        $liveDisks = array_values(array_unique(array_filter(array_map(
            static fn (BackupArtifactData $artifact): ?string => $artifact->sourceDisk,
            $manifest->media,
        ))));

        throw_if($mediaDisk === $this->store->diskName() || in_array($mediaDisk, $liveDisks, true), InvalidArgumentException::class, 'Scratch media disk must be different from every live media disk and the backup disk.');

        throw_unless($this->safeRelativePath($mediaPrefix), InvalidArgumentException::class, 'Scratch media prefix is unsafe.');

        $targetDisk = $this->filesystems->disk($mediaDisk);

        throw_if($targetDisk->exists($mediaPrefix) || $targetDisk->allFiles($mediaPrefix) !== [], InvalidArgumentException::class, 'Scratch media prefix must be empty.');
    }

    private function assertArtifacts(BackupManifestData $manifest): void
    {
        foreach ([$manifest->database, ...$manifest->media] as $artifact) {
            $issue = $this->store->artifactIssue($manifest->snapshotId, $artifact->path, $artifact->bytes, $artifact->sha256);

            throw_if($issue !== null, RuntimeException::class, 'Backup snapshot failed integrity verification: ' . $issue . '.');
        }

        foreach ($manifest->media as $artifact) {
            throw_if($artifact->sourceDisk === null || $artifact->sourceDisk === ''
                || $artifact->sourcePath === null || ! $this->safeRelativePath($artifact->sourcePath), RuntimeException::class, 'Backup media artifact has an unsafe source target.');
        }
    }

    private function assertLocalArtifact(BackupArtifactData $artifact, string $localPath): void
    {
        $bytes = filesize($localPath);
        $checksum = hash_file('sha256', $localPath);

        throw_if($bytes !== $artifact->bytes || ! is_string($checksum) || ! hash_equals($artifact->sha256, $checksum), RuntimeException::class, 'Downloaded backup artifact failed integrity verification.');
    }

    private function gunzip(string $sourcePath, string $destinationPath): void
    {
        $source = gzopen($sourcePath, 'rb');
        $destination = fopen($destinationPath, 'wb');

        if ($source === false || $destination === false) {
            if (is_resource($source)) {
                gzclose($source);
            }

            if (is_resource($destination)) {
                fclose($destination);
            }

            throw new RuntimeException('Unable to decompress the database backup.');
        }

        try {
            throw_if(stream_copy_to_stream($source, $destination) === false, RuntimeException::class, 'Unable to decompress the database backup.');
        } finally {
            gzclose($source);
            fclose($destination);
        }
    }

    private function restoreMedia(
        BackupManifestData $manifest,
        ?string $mediaDisk,
        ?string $mediaPrefix,
        BackupTemporaryFiles $temporaryFiles,
    ): int {
        if ($manifest->media === []) {
            return 0;
        }

        throw_if($mediaDisk === null || $mediaPrefix === null, RuntimeException::class, 'Scratch media target was not validated.');

        $target = $this->filesystems->disk($mediaDisk);

        foreach ($manifest->media as $artifact) {
            throw_if($artifact->sourcePath === null || ! $this->safeRelativePath($artifact->sourcePath), RuntimeException::class, 'Backup media artifact has an unsafe source path.');

            $temporaryPath = $temporaryFiles->create('capell-restore-media-');
            $this->store->download($artifact->path, $temporaryPath);
            $this->assertLocalArtifact($artifact, $temporaryPath);
            $stream = fopen($temporaryPath, 'rb');

            throw_if($stream === false, RuntimeException::class, 'Unable to read a restored media artifact.');

            try {
                throw_unless($target->put(rtrim($mediaPrefix, '/') . '/' . $artifact->sourcePath, $stream), RuntimeException::class, 'Unable to write a restored media artifact.');
            } finally {
                fclose($stream);
            }
        }

        return count($manifest->media);
    }

    private function runDoctor(string $connectionName, string $restoredDatabase): string
    {
        try {
            $process = $this->processes->make(
                [
                    PHP_BINARY,
                    base_path('artisan'),
                    'capell:doctor',
                    '--json',
                    '--connection=' . $connectionName,
                    '--database=' . $restoredDatabase,
                ],
                base_path(),
                ArtisanProcessEnvironment::prepare(),
            );
            $process->setTimeout(max(60, (int) $this->config->get('backup.process_timeout_seconds', 3600)));
            $process->mustRun();
            $report = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new RuntimeException('Scratch restore doctor verification failed.');
        }

        throw_if(! is_array($report) || ($report['status'] ?? null) !== 'passed', RuntimeException::class, 'Scratch restore doctor verification failed.');

        return 'passed';
    }

    private function safeRelativePath(string $path): bool
    {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, "\0")) {
            return false;
        }

        return array_all(explode('/', str_replace('\\', '/', $path)), fn ($segment): bool => ! in_array($segment, ['', '.', '..'], true));
    }

    private function samePath(string $left, string $right): bool
    {
        $realLeft = realpath($left);
        $realRight = realpath($right);

        if ($realLeft !== false && $realRight !== false) {
            return $realLeft === $realRight;
        }

        return rtrim(str_replace('\\', '/', $left), '/') === rtrim(str_replace('\\', '/', $right), '/');
    }
}

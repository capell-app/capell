<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Backup;

use Capell\Core\Data\Backup\BackupArtifactData;
use Capell\Core\Data\Backup\BackupManifestData;
use Capell\Core\Data\Backup\BackupRestoreResultData;
use Capell\Core\Support\Backup\BackupArtifactStore;
use Capell\Core\Support\Backup\BackupTemporaryFiles;
use Capell\Core\Support\Backup\DatabaseBackupDriverRegistry;
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

        if ($manifest->snapshotId !== $snapshotId) {
            throw new RuntimeException('Backup manifest identity does not match the requested snapshot.');
        }

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

        if (! is_string($prefix) || $prefix === '' || ! str_starts_with($scratchDatabase, $prefix)
            || preg_match('/\A[A-Za-z][A-Za-z0-9_]{2,62}\z/', $scratchDatabase) !== 1) {
            throw new InvalidArgumentException('Restore requires a safe scratch database name with the configured prefix.');
        }

        $liveDatabase = $this->config->get(sprintf('database.connections.%s.database', $manifest->connectionName));

        if (! is_string($liveDatabase) || $liveDatabase === '') {
            throw new RuntimeException('The live database target is not configured.');
        }

        if ($manifest->databaseDriver === 'sqlite') {
            $scratchDirectory = (string) $this->config->get('backup.scratch.sqlite_directory', '');
            $scratchPath = rtrim($scratchDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $scratchDatabase . '.sqlite';

            if ($scratchDirectory === '' || $this->samePath($liveDatabase, $scratchPath)) {
                throw new InvalidArgumentException('Scratch restore database must differ from the live database.');
            }

            return;
        }

        if ($liveDatabase === $scratchDatabase) {
            throw new InvalidArgumentException('Scratch restore database must differ from the live database.');
        }
    }

    private function assertMediaTarget(BackupManifestData $manifest, ?string $mediaDisk, ?string $mediaPrefix): void
    {
        if ($manifest->media === []) {
            return;
        }

        if ($mediaDisk === null || $mediaDisk === '' || $mediaPrefix === null || $mediaPrefix === '') {
            throw new InvalidArgumentException('Media restore requires a scratch media disk and non-empty prefix.');
        }

        $liveDisks = array_values(array_unique(array_filter(array_map(
            static fn (BackupArtifactData $artifact): ?string => $artifact->sourceDisk,
            $manifest->media,
        ))));

        if ($mediaDisk === $this->store->diskName() || in_array($mediaDisk, $liveDisks, true)) {
            throw new InvalidArgumentException('Scratch media disk must be different from every live media disk and the backup disk.');
        }

        if (! $this->safeRelativePath($mediaPrefix)) {
            throw new InvalidArgumentException('Scratch media prefix is unsafe.');
        }

        $targetDisk = $this->filesystems->disk($mediaDisk);

        if ($targetDisk->exists($mediaPrefix) || $targetDisk->allFiles($mediaPrefix) !== []) {
            throw new InvalidArgumentException('Scratch media prefix must be empty.');
        }
    }

    private function assertArtifacts(BackupManifestData $manifest): void
    {
        foreach ([$manifest->database, ...$manifest->media] as $artifact) {
            $issue = $this->store->artifactIssue($manifest->snapshotId, $artifact->path, $artifact->bytes, $artifact->sha256);

            if ($issue !== null) {
                throw new RuntimeException('Backup snapshot failed integrity verification: ' . $issue . '.');
            }
        }

        foreach ($manifest->media as $artifact) {
            if ($artifact->sourceDisk === null || $artifact->sourceDisk === ''
                || $artifact->sourcePath === null || ! $this->safeRelativePath($artifact->sourcePath)) {
                throw new RuntimeException('Backup media artifact has an unsafe source target.');
            }
        }
    }

    private function assertLocalArtifact(BackupArtifactData $artifact, string $localPath): void
    {
        $bytes = filesize($localPath);
        $checksum = hash_file('sha256', $localPath);

        if ($bytes !== $artifact->bytes || ! is_string($checksum) || ! hash_equals($artifact->sha256, $checksum)) {
            throw new RuntimeException('Downloaded backup artifact failed integrity verification.');
        }
    }

    private function gunzip(string $sourcePath, string $destinationPath): void
    {
        $source = gzopen($sourcePath, 'rb');
        $destination = fopen($destinationPath, 'wb');

        if ($source === false || $destination === false) {
            is_resource($source) && gzclose($source);
            is_resource($destination) && fclose($destination);
            throw new RuntimeException('Unable to decompress the database backup.');
        }

        try {
            if (stream_copy_to_stream($source, $destination) === false) {
                throw new RuntimeException('Unable to decompress the database backup.');
            }
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

        if ($mediaDisk === null || $mediaPrefix === null) {
            throw new RuntimeException('Scratch media target was not validated.');
        }

        $target = $this->filesystems->disk($mediaDisk);

        foreach ($manifest->media as $artifact) {
            if ($artifact->sourcePath === null || ! $this->safeRelativePath($artifact->sourcePath)) {
                throw new RuntimeException('Backup media artifact has an unsafe source path.');
            }

            $temporaryPath = $temporaryFiles->create('capell-restore-media-');
            $this->store->download($artifact->path, $temporaryPath);
            $this->assertLocalArtifact($artifact, $temporaryPath);
            $stream = fopen($temporaryPath, 'rb');

            if ($stream === false) {
                throw new RuntimeException('Unable to read a restored media artifact.');
            }

            try {
                if (! $target->put(rtrim($mediaPrefix, '/') . '/' . $artifact->sourcePath, $stream)) {
                    throw new RuntimeException('Unable to write a restored media artifact.');
                }
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
            );
            $process->setTimeout(max(60, (int) $this->config->get('backup.process_timeout_seconds', 3600)));
            $process->mustRun();
            $report = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new RuntimeException('Scratch restore doctor verification failed.');
        }

        if (! is_array($report) || ($report['status'] ?? null) !== 'passed') {
            throw new RuntimeException('Scratch restore doctor verification failed.');
        }

        return 'passed';
    }

    private function safeRelativePath(string $path): bool
    {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, "\0")) {
            return false;
        }

        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
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

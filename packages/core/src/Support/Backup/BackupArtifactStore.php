<?php

declare(strict_types=1);

namespace Capell\Core\Support\Backup;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use RuntimeException;
use UnexpectedValueException;

final readonly class BackupArtifactStore
{
    public function __construct(
        private FilesystemManager $filesystems,
        private Repository $config,
    ) {}

    public function assertAvailable(): void
    {
        if ($this->config->get('backup.enabled') !== true) {
            throw new RuntimeException('Backups are disabled.');
        }

        $this->diskName();
        $this->prefix();
    }

    public function diskName(): string
    {
        $disk = $this->config->get('backup.disk');

        if (! is_string($disk) || trim($disk) === '') {
            throw new RuntimeException('The backup storage disk is not configured.');
        }

        return trim($disk);
    }

    public function prefix(): string
    {
        $prefix = $this->config->get('backup.prefix');

        if (! is_string($prefix)) {
            throw new RuntimeException('The backup storage prefix is invalid.');
        }

        $prefix = trim($prefix, " \t\n\r\0\x0B/");

        if ($prefix === '' || $this->containsUnsafeSegment($prefix)) {
            throw new RuntimeException('The backup storage prefix is invalid.');
        }

        return $prefix;
    }

    public function snapshotPath(string $snapshotId, string $relativePath = ''): string
    {
        if (preg_match('/\A[0-9]{8}T[0-9]{6}Z-[a-f0-9]{12}\z/', $snapshotId) !== 1) {
            throw new RuntimeException('The backup snapshot identifier is invalid.');
        }

        $path = $this->prefix() . '/' . $snapshotId;

        if ($relativePath === '') {
            return $path;
        }

        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');

        if ($relativePath === '' || $this->containsUnsafeSegment($relativePath)) {
            throw new RuntimeException('The backup artifact path is invalid.');
        }

        return $path . '/' . $relativePath;
    }

    public function putLocalFile(string $path, string $localPath): void
    {
        $stream = fopen($localPath, 'rb');

        if ($stream === false) {
            throw new RuntimeException('Unable to read a local backup artifact.');
        }

        try {
            if (! $this->disk()->put($path, $stream)) {
                throw new RuntimeException('Unable to write a backup artifact.');
            }
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    public function putManifest(string $snapshotId, array $manifest): void
    {
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if (! $this->disk()->put($this->snapshotPath($snapshotId, 'manifest.json'), $json . PHP_EOL)) {
            throw new RuntimeException('Unable to write the backup manifest.');
        }
    }

    /** @return list<string> */
    public function snapshotIds(): array
    {
        $prefix = preg_quote($this->prefix(), '#');
        $snapshotIds = [];

        foreach ($this->disk()->allFiles($this->prefix()) as $path) {
            if (preg_match('#\A' . $prefix . '/([0-9]{8}T[0-9]{6}Z-[a-f0-9]{12})/#', $path, $matches) === 1) {
                $snapshotIds[$matches[1]] = true;
            }
        }

        $ids = array_keys($snapshotIds);
        sort($ids);

        return $ids;
    }

    /** @return array<string, mixed> */
    public function manifest(string $snapshotId): array
    {
        $path = $this->snapshotPath($snapshotId, 'manifest.json');

        if (! $this->disk()->exists($path)) {
            throw new UnexpectedValueException('Backup manifest is missing.');
        }

        $contents = $this->disk()->get($path);

        if (! is_string($contents)) {
            throw new UnexpectedValueException('Backup manifest is unreadable.');
        }

        $manifest = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($manifest)) {
            throw new UnexpectedValueException('Backup manifest is invalid.');
        }

        return $manifest;
    }

    public function artifactIssue(string $snapshotId, string $path, int $bytes, string $sha256): ?string
    {
        $snapshotPath = $this->snapshotPath($snapshotId) . '/';

        if (! str_starts_with($path, $snapshotPath) || $this->containsUnsafeSegment($path)) {
            return 'contains an out-of-snapshot artifact path';
        }

        if (! $this->disk()->exists($path)) {
            return 'has a missing artifact';
        }

        if ($this->disk()->size($path) !== $bytes) {
            return 'has an artifact size mismatch';
        }

        $stream = $this->disk()->readStream($path);

        if (! is_resource($stream)) {
            return 'has an unreadable artifact';
        }

        try {
            $context = hash_init('sha256');
            hash_update_stream($context, $stream);
            $actualChecksum = hash_final($context);
        } finally {
            fclose($stream);
        }

        return hash_equals($sha256, $actualChecksum) ? null : 'has an artifact checksum mismatch';
    }

    public function download(string $path, string $localPath): void
    {
        $source = $this->disk()->readStream($path);
        $destination = fopen($localPath, 'wb');

        if (! is_resource($source) || $destination === false) {
            is_resource($source) && fclose($source);
            is_resource($destination) && fclose($destination);
            throw new RuntimeException('Unable to download a backup artifact.');
        }

        try {
            if (stream_copy_to_stream($source, $destination) === false) {
                throw new RuntimeException('Unable to download a backup artifact.');
            }
        } finally {
            fclose($source);
            fclose($destination);
        }
    }

    public function deleteSnapshot(string $snapshotId): void
    {
        if (! $this->disk()->deleteDirectory($this->snapshotPath($snapshotId))) {
            throw new RuntimeException('Unable to remove an incomplete backup snapshot.');
        }
    }

    public function disk(): Filesystem
    {
        return $this->filesystems->disk($this->diskName());
    }

    private function containsUnsafeSegment(string $path): bool
    {
        if (str_contains($path, "\0")) {
            return true;
        }

        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return true;
            }
        }

        return false;
    }
}

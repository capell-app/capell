<?php

declare(strict_types=1);

namespace Capell\Core\Support\Backup;

use Capell\Core\Support\Json\JsonCodec;
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
        throw_if($this->config->get('backup.enabled') !== true, RuntimeException::class, 'Backups are disabled.');

        $this->diskName();
        $this->prefix();
    }

    public function diskName(): string
    {
        $disk = $this->config->get('backup.disk');

        throw_if(! is_string($disk) || trim($disk) === '', RuntimeException::class, 'The backup storage disk is not configured.');

        return trim($disk);
    }

    public function prefix(): string
    {
        $prefix = $this->config->get('backup.prefix');

        throw_unless(is_string($prefix), RuntimeException::class, 'The backup storage prefix is invalid.');

        $prefix = trim($prefix, " \t\n\r\0\x0B/");

        throw_if($prefix === '' || $this->containsUnsafeSegment($prefix), RuntimeException::class, 'The backup storage prefix is invalid.');

        return $prefix;
    }

    public function snapshotPath(string $snapshotId, string $relativePath = ''): string
    {
        throw_if(preg_match('/\A\d{8}T\d{6}Z-[a-f0-9]{12}\z/', $snapshotId) !== 1, RuntimeException::class, 'The backup snapshot identifier is invalid.');

        $path = $this->prefix() . '/' . $snapshotId;

        if ($relativePath === '') {
            return $path;
        }

        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');

        throw_if($relativePath === '' || $this->containsUnsafeSegment($relativePath), RuntimeException::class, 'The backup artifact path is invalid.');

        return $path . '/' . $relativePath;
    }

    public function putLocalFile(string $path, string $localPath): void
    {
        $stream = fopen($localPath, 'rb');

        throw_if($stream === false, RuntimeException::class, 'Unable to read a local backup artifact.');

        try {
            throw_unless($this->disk()->put($path, $stream), RuntimeException::class, 'Unable to write a backup artifact.');
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    public function putManifest(string $snapshotId, array $manifest): void
    {
        $json = JsonCodec::encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        throw_unless($this->disk()->put($this->snapshotPath($snapshotId, 'manifest.json'), $json . PHP_EOL), RuntimeException::class, 'Unable to write the backup manifest.');
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

        throw_unless($this->disk()->exists($path), UnexpectedValueException::class, 'Backup manifest is missing.');

        $contents = $this->disk()->get($path);

        throw_unless(is_string($contents), UnexpectedValueException::class, 'Backup manifest is unreadable.');

        $manifest = JsonCodec::decode($contents);

        throw_unless(is_array($manifest), UnexpectedValueException::class, 'Backup manifest is invalid.');

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
            if (is_resource($source)) {
                fclose($source);
            }

            if (is_resource($destination)) {
                fclose($destination);
            }

            throw new RuntimeException('Unable to download a backup artifact.');
        }

        try {
            throw_if(stream_copy_to_stream($source, $destination) === false, RuntimeException::class, 'Unable to download a backup artifact.');
        } finally {
            fclose($source);
            fclose($destination);
        }
    }

    public function deleteSnapshot(string $snapshotId): void
    {
        throw_unless($this->disk()->deleteDirectory($this->snapshotPath($snapshotId)), RuntimeException::class, 'Unable to remove an incomplete backup snapshot.');
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

        return array_any(explode('/', str_replace('\\', '/', $path)), fn ($segment): bool => in_array($segment, ['', '.', '..'], true));
    }
}

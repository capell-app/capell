<?php

declare(strict_types=1);

namespace Capell\Core\Support\Migration;

use JsonException;
use RuntimeException;

final class MigrationPublishManifest
{
    /**
     * @var array<string, array{source_sha256: string, published_sha256: string}>
     */
    private array $entries;

    private bool $dirty = false;

    public function __construct(
        private readonly string $path,
    ) {
        $this->entries = $this->read();
    }

    public static function forApplication(): self
    {
        return new self(storage_path('app/capell/migration-publish-manifest.json'));
    }

    public function shouldPublish(string $type, string $source, string $destination): bool
    {
        $sourceHash = $this->hash($source);
        $destinationHash = is_file($destination) ? $this->hash($destination) : null;
        $entry = $this->entries[$this->key($type, $destination)] ?? null;

        if ($entry === null) {
            if ($destinationHash !== null && ! hash_equals($sourceHash, $destinationHash)) {
                $this->record($type, $destination, $sourceHash, $sourceHash);

                return false;
            }

            return true;
        }

        if ($destinationHash !== null && ! hash_equals($entry['published_sha256'], $destinationHash)) {
            return false;
        }

        return true;
    }

    public function recordPublished(string $type, string $source, string $destination): void
    {
        $sourceHash = $this->hash($source);
        $this->record($type, $destination, $sourceHash, $sourceHash);
    }

    public function save(): void
    {
        if (! $this->dirty) {
            return;
        }

        $directory = dirname($this->path);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create migration publish manifest directory: %s', $directory));
        }

        ksort($this->entries);

        try {
            $json = json_encode([
                'version' => 1,
                'migrations' => $this->entries,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            throw new RuntimeException('Unable to encode the migration publish manifest.', 0, $jsonException);
        }

        $temporaryPath = $this->path . '.tmp.' . bin2hex(random_bytes(6));

        if (file_put_contents($temporaryPath, $json . PHP_EOL, LOCK_EX) === false
            || ! rename($temporaryPath, $this->path)) {
            @unlink($temporaryPath);

            throw new RuntimeException(sprintf('Unable to write migration publish manifest: %s', $this->path));
        }

        $this->dirty = false;
    }

    /**
     * @return array<string, array{source_sha256: string, published_sha256: string}>
     */
    private function read(): array
    {
        if (! is_file($this->path)) {
            return [];
        }

        $contents = file_get_contents($this->path);

        if (! is_string($contents)) {
            throw new RuntimeException(sprintf('Unable to read migration publish manifest: %s', $this->path));
        }

        try {
            $manifest = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            throw new RuntimeException(sprintf('Invalid migration publish manifest: %s', $this->path), 0, $jsonException);
        }

        if (! is_array($manifest) || ($manifest['version'] ?? null) !== 1 || ! is_array($manifest['migrations'] ?? null)) {
            throw new RuntimeException(sprintf('Invalid migration publish manifest: %s', $this->path));
        }

        $entries = [];

        foreach ($manifest['migrations'] as $key => $entry) {
            if (! is_string($key) || ! is_array($entry)
                || ! is_string($entry['source_sha256'] ?? null)
                || ! is_string($entry['published_sha256'] ?? null)
                || preg_match('/\A[a-f0-9]{64}\z/D', $entry['source_sha256']) !== 1
                || preg_match('/\A[a-f0-9]{64}\z/D', $entry['published_sha256']) !== 1) {
                throw new RuntimeException(sprintf('Invalid migration publish manifest entry: %s', $this->path));
            }

            $entries[$key] = [
                'source_sha256' => $entry['source_sha256'],
                'published_sha256' => $entry['published_sha256'],
            ];
        }

        return $entries;
    }

    private function record(
        string $type,
        string $destination,
        string $sourceHash,
        string $publishedHash,
    ): void {
        $key = $this->key($type, $destination);
        $entry = [
            'source_sha256' => $sourceHash,
            'published_sha256' => $publishedHash,
        ];

        if (($this->entries[$key] ?? null) === $entry) {
            return;
        }

        $this->entries[$key] = $entry;
        $this->dirty = true;
    }

    private function key(string $type, string $destination): string
    {
        return $type . '/' . basename($destination);
    }

    private function hash(string $path): string
    {
        $hash = hash_file('sha256', $path);

        if (! is_string($hash)) {
            throw new RuntimeException(sprintf('Unable to hash migration file: %s', $path));
        }

        return $hash;
    }
}

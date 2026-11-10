<?php

declare(strict_types=1);

namespace Capell\Core\Support\Runtime;

use Carbon\CarbonImmutable;
use Illuminate\Filesystem\Filesystem;
use Throwable;

/**
 * Content identity of the Composer install set (vendor/composer/installed.json).
 *
 * Generated runtime manifests record the identity they were derived from and are
 * trusted only while it still matches. Modification times alone cannot be trusted:
 * deploys that preserve mtimes, same-second rewrites and non-atomic Composer writes
 * all leave a stale manifest looking current. A matching size and mtime is accepted
 * without hashing only once the file had settled well before the manifest was
 * generated; anything else is confirmed by content hash.
 */
final readonly class ComposerInstallIdentity
{
    private const int SETTLE_SECONDS = 2;

    public function __construct(
        private Filesystem $files,
        private string $basePath,
    ) {}

    /**
     * Capture the identity before building a manifest, so a change made while the
     * manifest is being built is detected on the next boot.
     *
     * @return array{sha256: string, size: int, mtime: int, generated_at: int}|null
     */
    public function snapshot(): ?array
    {
        $path = $this->installedPath();

        if (! is_file($path)) {
            return null;
        }

        clearstatcache(false, $path);
        $hash = hash_file('sha256', $path);
        $size = filesize($path);
        $mtime = filemtime($path);

        if ($hash === false || $size === false || $mtime === false) {
            return null;
        }

        return ['sha256' => $hash, 'size' => $size, 'mtime' => $mtime, 'generated_at' => CarbonImmutable::now()->getTimestamp()];
    }

    /** @param array{sha256: string, size: int, mtime: int, generated_at: int}|null $snapshot */
    public function record(string $identityPath, ?array $snapshot): void
    {
        if ($snapshot === null) {
            return;
        }

        try {
            $this->files->ensureDirectoryExists(dirname($identityPath));
            $this->files->replace($identityPath, '<?php return ' . var_export($snapshot, return: true) . ';' . PHP_EOL);

            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($identityPath, true);
            }
        } catch (Throwable) {
            // A read-only cache directory only costs a content check on later boots.
        }
    }

    public function matches(string $identityPath): bool
    {
        $installed = $this->installedPath();

        if (! is_file($installed)) {
            return true;
        }

        $recorded = $this->read($identityPath);

        if ($recorded === null) {
            return false;
        }

        clearstatcache(false, $installed);

        if (
            filesize($installed) === $recorded['size']
            && filemtime($installed) === $recorded['mtime']
            && $recorded['mtime'] < $recorded['generated_at'] - self::SETTLE_SECONDS
        ) {
            return true;
        }

        if (hash_file('sha256', $installed) !== $recorded['sha256']) {
            return false;
        }

        // Same content with a touched file (for example a deploy copy): remember the
        // new stat so later boots skip the hash.
        $this->record($identityPath, $this->snapshot());

        return true;
    }

    /** @return array{sha256: string, size: int, mtime: int, generated_at: int}|null */
    private function read(string $identityPath): ?array
    {
        if (! is_file($identityPath)) {
            return null;
        }

        try {
            $value = $this->files->getRequire($identityPath);
        } catch (Throwable) {
            return null;
        }

        if (
            ! is_array($value)
            || ! is_string($value['sha256'] ?? null)
            || ! is_int($value['size'] ?? null)
            || ! is_int($value['mtime'] ?? null)
            || ! is_int($value['generated_at'] ?? null)
        ) {
            return null;
        }

        return ['sha256' => $value['sha256'], 'size' => $value['size'], 'mtime' => $value['mtime'], 'generated_at' => $value['generated_at']];
    }

    private function installedPath(): string
    {
        return $this->basePath . '/vendor/composer/installed.json';
    }
}

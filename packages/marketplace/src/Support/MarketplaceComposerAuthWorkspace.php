<?php

declare(strict_types=1);

namespace Capell\Marketplace\Support;

use Capell\Core\Support\Json\JsonCodec;
use JsonException;
use RuntimeException;

/**
 * The throwaway Composer homes a Marketplace install writes its auth file into.
 *
 * Each authenticated install gets its own directory so one install's credentials
 * are never visible to another. A run that is killed mid-Composer — a worker
 * restart, a host OOM — never reaches its cleanup, so the directories pile up
 * with an auth file inside each. Sweeping them is part of starting a run rather
 * than a scheduled task nobody remembers to register.
 */
final class MarketplaceComposerAuthWorkspace
{
    public const string DIRECTORY_PREFIX = 'marketplace-auth-';

    /**
     * Long enough that a legitimately slow install still owns its directory —
     * Composer's own timeout is measured in minutes, not hours.
     */
    public const int STALE_AFTER_SECONDS = 3600;

    public function root(): string
    {
        return storage_path('framework/composer');
    }

    /**
     * Create an isolated Composer home for a single authenticated install.
     */
    public function create(): string
    {
        $path = $this->root() . '/' . self::DIRECTORY_PREFIX . bin2hex(random_bytes(8));

        $this->ensureDirectory($path);

        return $path;
    }

    /**
     * @param  array<string, mixed>  $composerAuth
     *
     * @throws JsonException
     */
    public function writeAuthFile(string $composerHome, array $composerAuth): void
    {
        $path = $composerHome . '/auth.json';
        $written = @file_put_contents(
            $path,
            JsonCodec::encode($composerAuth, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );

        throw_if($written === false, RuntimeException::class, 'Unable to write Composer authentication file.');

        @chmod($path, 0600);
    }

    /**
     * Directories left behind by a run that never reached its own cleanup.
     *
     * @return list<string>
     */
    public function stale(): array
    {
        $cutoff = time() - self::STALE_AFTER_SECONDS;
        $candidates = glob($this->root() . '/' . self::DIRECTORY_PREFIX . '*', GLOB_ONLYDIR);

        if ($candidates === false) {
            return [];
        }

        return array_values(array_filter(
            $candidates,
            static function (string $path) use ($cutoff): bool {
                $modifiedAt = @filemtime($path);

                // An in-flight install owns a directory younger than the cutoff,
                // so leaving it alone is the whole point of the age check.
                return $modifiedAt !== false && $modifiedAt < $cutoff;
            },
        ));
    }

    /**
     * @return int The number of stale directories removed.
     */
    public function sweep(): int
    {
        $stale = $this->stale();

        foreach ($stale as $path) {
            $this->removeDirectory($path);
        }

        return count($stale);
    }

    public function ensureDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        $created = @mkdir($path, 0755, true);

        throw_unless(
            $created || is_dir($path),
            RuntimeException::class,
            'Unable to create Composer home directory: ' . $path,
        );
    }

    public function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = scandir($path);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $itemPath = $path . DIRECTORY_SEPARATOR . $item;

            if (is_dir($itemPath)) {
                $this->removeDirectory($itemPath);

                continue;
            }

            @unlink($itemPath);
        }

        @rmdir($path);
    }
}

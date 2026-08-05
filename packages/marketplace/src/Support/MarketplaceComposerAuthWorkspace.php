<?php

declare(strict_types=1);

namespace Capell\Marketplace\Support;

use Capell\Core\Support\Json\JsonCodec;
use Capell\Marketplace\Jobs\RunMarketplaceInstallAttemptJob;
use Illuminate\Support\Facades\Date;
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
     * A directory is created when its install starts and is never touched again,
     * so its age is the age of the run that owns it. The queue kills that run at
     * the job timeout, which makes the job timeout the only cutoff that cannot
     * delete a live auth file — a literal would start deleting them the moment
     * an operator raises the configurable Composer timeout past it.
     */
    public static function staleAfterSeconds(): int
    {
        return RunMarketplaceInstallAttemptJob::jobTimeoutSeconds();
    }

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
        $cutoff = Date::now()->getTimestamp() - self::staleAfterSeconds();
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
            if ($item === '.') {
                continue;
            }

            if ($item === '..') {
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

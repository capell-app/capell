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
     * @param  int|null  $effectiveUserId  The owner a trusted directory must have besides root; defaults to the process's effective user.
     */
    public function __construct(private readonly ?int $effectiveUserId = null) {}

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
        $configured = config('capell.marketplace.auth_workspace_path');

        return is_string($configured) && $configured !== '' ? $configured : storage_path('framework/composer');
    }

    /**
     * Create an isolated Composer home for a single authenticated install.
     */
    public function create(): string
    {
        $path = $this->root() . '/' . self::DIRECTORY_PREFIX . bin2hex(random_bytes(8));

        $root = $this->root();
        $this->assertTrustedAncestors($root);
        if (! file_exists($root) && ! is_link($root)) {
            throw_unless(@mkdir($root, 0700), RuntimeException::class, __('capell-marketplace::marketplace.auth_workspace.create_root', ['path' => $root]));
        }

        $this->assertTrustedDirectory($root);
        throw_unless(@mkdir($path, 0700), RuntimeException::class, 'Unable to create a private Composer home.');

        if (! @chmod($path, 0700)) {
            $this->removeDirectory($path);
            throw new RuntimeException('Unable to create a private Composer home.');
        }

        clearstatcache(true, $path);
        if ((fileperms($path) & 0777) !== 0700) {
            $this->removeDirectory($path);
            throw new RuntimeException('Unable to secure the Composer home.');
        }

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
        $file = @fopen($path, 'x');
        throw_if($file === false, RuntimeException::class, 'Unable to create Composer authentication file.');

        try {
            throw_unless(@chmod($path, 0600), RuntimeException::class, 'Unable to secure Composer authentication file.');
            clearstatcache(true, $path);
            throw_unless((fileperms($path) & 0777) === 0600, RuntimeException::class, 'Unable to secure Composer authentication file.');
            $content = JsonCodec::encode($composerAuth, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            throw_unless(fwrite($file, $content) === strlen($content), RuntimeException::class, 'Unable to write Composer authentication file.');
        } finally {
            fclose($file);
        }
    }

    /**
     * Directories left behind by a run that never reached its own cleanup.
     *
     * @return list<string>
     */
    public function stale(): array
    {
        $root = $this->root();
        if (! file_exists($root) && ! is_link($root)) {
            return [];
        }

        $this->assertTrustedAncestors($root);
        $this->assertTrustedDirectory($root);

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
        $root = realpath($this->root());
        $parent = realpath(dirname($path));
        if ($root === false || $parent === false || ($parent !== $root && ! str_starts_with($parent, $root . DIRECTORY_SEPARATOR))) {
            return;
        }

        if (is_link($path)) {
            @unlink($path);

            return;
        }

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

            if (is_link($itemPath)) {
                @unlink($itemPath);

                continue;
            }

            if (is_dir($itemPath)) {
                $this->removeDirectory($itemPath);

                continue;
            }

            @unlink($itemPath);
        }

        @rmdir($path);
    }

    /**
     * The immediate parent must meet the full trusted-directory policy. Higher ancestors may be
     * shared system directories, so they only need to be unredirectable by another user: a
     * symlink must belong to root or the effective user, and a world-writable directory must
     * carry the sticky bit (as /tmp does).
     */
    private function assertTrustedAncestors(string $root): void
    {
        $parent = dirname($root);
        $this->assertTrustedDirectory($parent);

        for ($ancestor = dirname($parent); $ancestor !== dirname($ancestor); $ancestor = dirname($ancestor)) {
            clearstatcache(true, $ancestor);
            $stat = @lstat($ancestor);
            throw_if($stat === false, RuntimeException::class, __('capell-marketplace::marketplace.auth_workspace.directory', ['path' => $ancestor]));
            if (is_link($ancestor)) {
                throw_unless($this->isTrustedOwner($stat['uid']), RuntimeException::class, __('capell-marketplace::marketplace.auth_workspace.owner', ['path' => $ancestor]));

                continue;
            }

            throw_unless(is_dir($ancestor), RuntimeException::class, __('capell-marketplace::marketplace.auth_workspace.directory', ['path' => $ancestor]));
            throw_if(($stat['mode'] & 0002) !== 0 && ($stat['mode'] & 01000) === 0, RuntimeException::class, __('capell-marketplace::marketplace.auth_workspace.world_writable', ['path' => $ancestor]));
        }
    }

    private function isTrustedOwner(int $owner): bool
    {
        $effectiveUser = $this->effectiveUserId ?? (function_exists('posix_geteuid') ? posix_geteuid() : null);

        return $effectiveUser !== null && ($owner === 0 || $owner === $effectiveUser);
    }

    private function assertTrustedDirectory(string $path): void
    {
        clearstatcache(true, $path);
        throw_if(is_link($path), RuntimeException::class, __('capell-marketplace::marketplace.auth_workspace.symlink', ['path' => $path]));
        throw_unless(is_dir($path), RuntimeException::class, __('capell-marketplace::marketplace.auth_workspace.directory', ['path' => $path]));

        $owner = fileowner($path);
        throw_if($owner === false || ! $this->isTrustedOwner($owner), RuntimeException::class, __('capell-marketplace::marketplace.auth_workspace.owner', ['path' => $path]));
        $permissions = fileperms($path);
        throw_if($permissions === false || ($permissions & 0002) !== 0, RuntimeException::class, __('capell-marketplace::marketplace.auth_workspace.world_writable', ['path' => $path]));
    }
}

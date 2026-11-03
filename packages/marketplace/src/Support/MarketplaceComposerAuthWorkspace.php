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
    public function __construct(
        private readonly ?int $effectiveUserId = null,
        private readonly WorkspacePathInspector $inspector = new NativeWorkspacePathInspector,
    ) {}

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
        if ($this->inspector->stat($root) === false && ! $this->inspector->mkdir($root, 0700) && $this->inspector->stat($root) === false) {
            $this->fail('create_root', $root);
        }

        $this->assertTrustedDirectory($root);
        throw_unless($this->inspector->mkdir($path, 0700), RuntimeException::class, 'Unable to create a private Composer home.');
        $created = $this->inspector->stat($path);
        $uid = $this->effectiveUserId ?? $this->inspector->effectiveUserId();
        if ($created === false || ($created['mode'] & 0170000) !== 0040000 || (config('capell.marketplace.auth_workspace_trust', 'posix') === 'posix' && $created['uid'] !== $uid) || ($created['mode'] & 0777) !== 0700) {
            $this->fail('directory', $path);
        }

        if (! @chmod($path, 0700)) {
            $this->removeDirectory($path);
            throw new RuntimeException('Unable to create a private Composer home.');
        }

        clearstatcache(true, $path);
        if (($after = $this->inspector->stat($path)) === false || ($after['mode'] & 0177777) !== ($created['mode'] & 0177777) || $after['uid'] !== $created['uid'] || $after['ino'] !== $created['ino'] || $after['dev'] !== $created['dev']) {
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
            $opened = fstat($file);
            $entry = $this->inspector->stat($path);
            throw_unless($opened !== false && $entry !== false && ($entry['mode'] & 0170000) === 0100000 && ($entry['mode'] & 0777) === 0600 && $opened['ino'] === $entry['ino'] && $opened['dev'] === $entry['dev'], RuntimeException::class, 'Unable to secure Composer authentication file.');
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
        $this->assertTrustedAncestors($root);
        if ($this->inspector->stat($root) === false) {
            return [];
        }

        $this->assertTrustedDirectory($root);

        $cutoff = Date::now()->getTimestamp() - self::staleAfterSeconds();
        $candidates = $this->inspector->glob($this->root() . '/' . self::DIRECTORY_PREFIX . '*');

        if ($candidates === false) {
            $this->indeterminate();
        }

        return array_values(array_filter(
            $candidates,
            function (string $path) use ($cutoff): bool {
                $modifiedAt = $this->inspector->modifiedAt($path);
                if ($modifiedAt === false) {
                    $this->indeterminate();
                }

                // An in-flight install owns a directory younger than the cutoff,
                // so leaving it alone is the whole point of the age check.
                return $modifiedAt < $cutoff;
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

    private function assertTrustedAncestors(string $root): void
    {
        $mode = config('capell.marketplace.auth_workspace_trust', 'posix');
        $uid = $this->effectiveUserId ?? $this->inspector->effectiveUserId();
        $override = config('capell.marketplace.auth_workspace_path');
        if ($mode !== 'posix' && $mode !== 'acl' || ($mode === 'posix' && $uid === null) || ($mode === 'acl' && (! is_string($override) || $override === '' || $root !== $override))) {
            $this->fail('trust_mode', $root);
        }

        if ($mode === 'acl') {
            static $logged = false;
            if (! $logged) {
                logger()->warning('Composer workspace ACL trust is enabled; the operator must secure the configured path.');
                $logged = true;
            }
        }

        $parent = dirname($root);
        $parentStat = $this->inspector->stat($parent);
        if ($parentStat !== false && ($parentStat['mode'] & 0170000) === 0120000) {
            $this->fail('symlink', $parent);
        }

        // A sticky world-writable ancestor such as /tmp cannot rename or remove entries it does
        // not own, so it is safe once the workspace's own parent belongs to the worker; every
        // ancestor is still owner-checked and a world-writable directory without the sticky bit
        // is still refused.
        $stickyAllowed = $uid !== null && $parentStat !== false && $parentStat['uid'] === $uid;
        $this->walkAncestors($parent, $stickyAllowed, $mode === 'acl');
    }

    private function walkAncestors(string $path, bool $stickyAllowed, bool $acl): void
    {
        $visited = [];
        while (! isset($visited[$path])) {
            $visited[$path] = true;
            $stat = $this->inspector->stat($path);
            if ($stat === false) {
                $this->fail('directory', $path);
            }

            if (! $acl && ! $this->isTrustedOwner($stat['uid'])) {
                $this->fail('owner', $path);
            }

            $type = $stat['mode'] & 0170000;
            if ($type === 0120000) {
                $target = $this->inspector->realpath($path);
                if ($target === false) {
                    $this->fail('directory', $path);
                }

                $this->walkAncestors($target, $stickyAllowed, $acl);
            } elseif ($type !== 0040000) {
                $this->fail('directory', $path);
            } else {
                if (($stat['mode'] & 0002) !== 0 && (! $stickyAllowed || ($stat['mode'] & 01000) === 0)) {
                    $this->fail('world_writable', $path);
                }

                if (($stat['mode'] & 0020) !== 0 && (! $stickyAllowed || ($stat['mode'] & 01002) !== 01002)) {
                    $this->fail('group_writable', $path);
                }
            }

            $next = dirname($path);
            if ($next === $path) {
                break;
            }

            $path = $next;
        }
    }

    private function isTrustedOwner(int $owner): bool
    {
        if (config('capell.marketplace.auth_workspace_trust', 'posix') === 'acl') {
            return true;
        }

        $effectiveUser = $this->effectiveUserId ?? $this->inspector->effectiveUserId();

        return $effectiveUser !== null && ($owner === 0 || $owner === $effectiveUser);
    }

    private function assertTrustedDirectory(string $path): void
    {
        $stat = $this->inspector->stat($path);
        if ($stat !== false && ($stat['mode'] & 0170000) === 0120000) {
            $this->fail('symlink', $path);
        }

        if ($stat === false || ($stat['mode'] & 0170000) !== 0040000) {
            $this->fail('directory', $path);
        }

        if (! $this->isTrustedOwner($stat['uid'])) {
            $this->fail('owner', $path);
        }

        if (($stat['mode'] & 0002) !== 0) {
            $this->fail('world_writable', $path);
        }

        if (($stat['mode'] & 0020) !== 0) {
            $this->fail('group_writable', $path);
        }
    }

    private function fail(string $key, string $path): never
    {
        $message = match ($key) {
            'create_root' => __('capell-marketplace::marketplace.auth_workspace.create_root', ['path' => $path]),
            'symlink' => __('capell-marketplace::marketplace.auth_workspace.symlink', ['path' => $path]),
            'owner' => __('capell-marketplace::marketplace.auth_workspace.owner', ['path' => $path]),
            'world_writable' => __('capell-marketplace::marketplace.auth_workspace.world_writable', ['path' => $path]),
            'group_writable' => __('capell-marketplace::marketplace.auth_workspace.group_writable', ['path' => $path]),
            'trust_mode' => __('capell-marketplace::marketplace.auth_workspace.trust_mode'),
            default => __('capell-marketplace::marketplace.auth_workspace.directory', ['path' => $path]),
        };
        throw new WorkspaceException((string) $message, (string) __('capell-marketplace::marketplace.auth_workspace.untrusted'));
    }

    private function indeterminate(): never
    {
        $message = (string) __('capell-marketplace::marketplace.auth_workspace.indeterminate');
        throw new WorkspaceException($message, $message, WorkspaceFailure::Indeterminate);
    }
}

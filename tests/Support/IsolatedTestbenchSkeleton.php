<?php

declare(strict_types=1);

namespace Capell\Tests\Support;

use Carbon\CarbonImmutable;

use function Orchestra\Testbench\default_skeleton_path;

use RuntimeException;

/**
 * Gives every concurrent test process its own copy of the Testbench skeleton.
 *
 * Testbench boots the application from a single shared skeleton inside
 * vendor/orchestra/testbench-core/laravel. Several test files create and delete real files in
 * that skeleton (most notably app/Providers/Filament/AdminPanelProvider.php, which decides which
 * prompts InstallCommand and SetupCommand ask). When shards or Paratest workers run at the same
 * time they see each other's files, and the interactive-prompt expectations fail at random.
 *
 * Each process boots from a disposable directory outside the repository.
 * Everything is copied — crucially app/ and bootstrap/cache/ — except storage/, which is recreated
 * as an empty directory tree because the shared one accumulates gigabytes of test artefacts. The
 * Skeleton symlinks are omitted; vendor and public/storage are linked to their owned targets.
 * If linking fails, the checkout vendor tree and empty public storage are copied instead.
 */
final class IsolatedTestbenchSkeleton
{
    private const string OwnerMarker = '.capell-skeleton';

    private const string Prefix = 'capell-testbench-';

    /**
     * Directories recreated empty rather than copied, relative to the skeleton root.
     *
     * @var list<string>
     */
    private const SCAFFOLDED_DIRECTORIES = [
        'storage/app/capell',
        'storage/app/framework',
        'storage/app/private',
        'storage/app/public',
        'storage/app/testing',
        'storage/capell/php-file-backups',
        'storage/framework/cache/data',
        'storage/framework/capell-static-artifacts',
        'storage/framework/composer',
        'storage/framework/data',
        'storage/framework/sessions',
        'storage/framework/testing',
        'storage/framework/views',
        'storage/logs',
        'storage/media-library/temp',
    ];

    private static ?string $preparedPath = null;

    private static ?int $preparedPid = null;

    /**
     * Resolve the base path this process should boot the Laravel application from.
     *
     * Serial and parallel processes both need isolation from other worktrees and runs.
     */
    public static function basePath(): string
    {
        if (self::$preparedPath !== null && self::$preparedPid === getmypid()) {
            return self::$preparedPath;
        }

        $sourcePath = default_skeleton_path();

        throw_if($sourcePath === false, RuntimeException::class, 'Unable to resolve the Testbench skeleton path.');

        $token = self::token();

        // Every process needs its own skeleton, including ordinary serial runs.
        $token = ($token ?? 'serial') . '-' . bin2hex(random_bytes(8));

        // The directory name keeps the word "testbench": application code guards such as
        // ClearCachesAction::shouldSkipOptimizeClearForTestbench() recognise a Testbench skeleton
        // from the bootstrap path.
        $targetPath = self::pathForToken($token);

        // UNIQUE_TEST_TOKEN differs per run, so skeletons are never reused: without this
        // they accumulated (81 GB observed) and Larastan's Testbench boot walked them all.
        self::removeStaleSkeletons(dirname($targetPath), $targetPath);
        self::prepare($sourcePath, $targetPath);
        // A pcntl_fork child inherits shutdown functions; only the creating process may delete.
        $ownerPid = getmypid();
        register_shutdown_function(static function () use ($targetPath, $ownerPid): void {
            if (getmypid() === $ownerPid) {
                self::delete($targetPath);
            }
        });

        self::$preparedPid = getmypid() ?: null;

        return self::$preparedPath = $targetPath;
    }

    /**
     * Remove skeletons left by processes that exited without their shutdown cleanup
     * (killed or crashed). Only directories untouched for longer than any plausible
     * run are removed, so a concurrent suite's live skeletons survive.
     */
    public static function removeStaleSkeletons(string $directory, ?string $keep = null, int $staleAfterSeconds = 21600, ?callable $isAlive = null): int
    {
        $entries = is_dir($directory) ? scandir($directory) : false;

        if ($entries === false) {
            return 0;
        }

        $removed = 0;
        // Plain Carbon, not the Date facade: this runs before the application exists.
        $cutoff = CarbonImmutable::now()->getTimestamp() - $staleAfterSeconds;

        foreach ($entries as $entry) {
            if (! str_starts_with($entry, self::Prefix)) {
                continue;
            }

            $path = $directory . '/' . $entry;

            if ($path === $keep) {
                continue;
            }

            if (is_link($path)) {
                continue;
            }

            if (! is_dir($path)) {
                continue;
            }

            if (self::isStale($path, $cutoff, $isAlive)) {
                self::delete($path);
                $removed++;
            }
        }

        return $removed;
    }

    /** Only a valid marker for this checkout permits pruning. */
    private static function isStale(string $path, int $cutoff, ?callable $isAlive): bool
    {
        $markerPath = $path . '/' . self::OwnerMarker;
        if (is_link($markerPath) || ! is_file($markerPath)) {
            return false;
        }

        $marker = @file_get_contents($markerPath);
        $owner = is_string($marker) ? json_decode($marker, true) : null;
        $checkout = realpath(dirname(__DIR__, 2));
        if (! is_array($owner) || ! is_string($checkout) || ($owner['checkout'] ?? null) !== $checkout || ! is_int($owner['pid'] ?? null) || $owner['pid'] <= 0) {
            return false;
        }

        $modifiedAt = filemtime($path);

        return $modifiedAt !== false && $modifiedAt < $cutoff && ! ($isAlive ?? self::processIsAlive(...))($owner['pid']);
    }

    private static function processIsAlive(int $pid): bool
    {
        if (function_exists('posix_kill')) {
            return posix_kill($pid, 0) || posix_get_last_error() === 1;
        }

        return true;
    }

    private static function pathForToken(string $token): string
    {
        $root = getenv('CAPELL_TESTBENCH_SKELETON_ROOT');
        $root = is_string($root) && $root !== '' ? rtrim($root, DIRECTORY_SEPARATOR) : sys_get_temp_dir() . '/capell-testbench-skeletons-' . hash('sha256', (string) realpath(dirname(__DIR__, 2)));

        return $root . DIRECTORY_SEPARATOR . self::Prefix . $token;
    }

    /**
     * The parallel process identifier.
     *
     * ParaTest provides UNIQUE_TEST_TOKEN per run and process. Prefer it so simultaneous test
     * suites cannot reuse and delete each other's skeletons, while retaining TEST_TOKEN as a
     * compatibility fallback.
     */
    private static function token(): ?string
    {
        $token = getenv('UNIQUE_TEST_TOKEN');

        if (! is_string($token) || $token === '') {
            $token = getenv('TEST_TOKEN');
        }

        if (! is_string($token) || $token === '') {
            return null;
        }

        $sanitised = preg_replace('/[^A-Za-z0-9_-]/', '-', $token);

        return is_string($sanitised) && $sanitised !== '' ? $sanitised : null;
    }

    private static function prepare(string $sourcePath, string $targetPath, ?callable $link = null, ?string $vendorPath = null): void
    {
        $vendorPath ??= dirname(__DIR__, 2) . '/vendor';
        $link ??= static fn (string $source, string $target): bool => @symlink($source, $target);

        throw_if(! @mkdir($targetPath, 0o777, true), RuntimeException::class, sprintf('Unable to create the isolated Testbench skeleton at [%s].', $targetPath));

        throw_if(file_put_contents($targetPath . '/' . self::OwnerMarker, json_encode(['checkout' => realpath(dirname(__DIR__, 2)), 'pid' => getmypid()], JSON_THROW_ON_ERROR)) === false, RuntimeException::class, sprintf('Unable to mark the isolated Testbench skeleton at [%s].', $targetPath));

        $entries = scandir($sourcePath);

        throw_if($entries === false, RuntimeException::class, sprintf('Unable to read the Testbench skeleton at [%s].', $sourcePath));

        foreach ($entries as $entry) {
            if ($entry === '.') {
                continue;
            }

            if ($entry === '..') {
                continue;
            }

            if ($entry === self::OwnerMarker) {
                continue;
            }

            if ($entry === 'storage') {
                continue;
            }

            if ($entry === 'vendor') {
                continue;
            }

            self::copy($sourcePath . '/' . $entry, $targetPath . '/' . $entry);
        }

        foreach (self::SCAFFOLDED_DIRECTORIES as $directory) {
            $path = $targetPath . '/' . $directory;

            throw_if(! is_dir($path) && ! mkdir($path, 0o777, true) && ! is_dir($path), RuntimeException::class, sprintf('Unable to create the directory [%s].', $path));
        }

        // Testbench and Composer actions must resolve the current checkout's loader.
        if (! $link($vendorPath, $targetPath . '/vendor')) {
            self::copy($vendorPath, $targetPath . '/vendor', true);
        }

        // Re-point the storage link at this copy's storage rather than the shared skeleton's.
        if (is_dir($targetPath . '/public') && ! file_exists($targetPath . '/public/storage') && ! is_link($targetPath . '/public/storage')) {
            if (! $link($targetPath . '/storage/app/public', $targetPath . '/public/storage')) {
                self::copy($targetPath . '/storage/app/public', $targetPath . '/public/storage', true);
            }
        }
    }

    /** @param list<string> $ancestors */
    private static function copy(string $source, string $target, bool $followLinks = false, array $ancestors = []): void
    {
        // Symlinks in the skeleton (public/storage) point back at the shared skeleton, which would
        // re-introduce cross-process sharing. They are recreated against the copy instead.
        // The package skeleton's vendor link points back to the tree being copied.
        if (is_link($source) && (! $followLinks || str_ends_with(str_replace('\\', '/', $source), '/orchestra/testbench-core/laravel/vendor'))) {
            return;
        }

        if (! is_dir($source)) {
            throw_if(! @copy($source, $target), RuntimeException::class, sprintf('Unable to copy [%s] to [%s].', $source, $target));

            return;
        }

        throw_if(! is_dir($target) && ! mkdir($target, 0o777, true) && ! is_dir($target), RuntimeException::class, sprintf('Unable to create the directory [%s].', $target));

        $resolved = realpath($source);
        throw_if($resolved === false || in_array($resolved, $ancestors, true), RuntimeException::class, sprintf('Unable to copy cyclic or inaccessible directory [%s].', $source));
        $ancestors[] = $resolved;
        $entries = @scandir($source);
        throw_if($entries === false, RuntimeException::class, sprintf('Unable to read directory [%s].', $source));

        foreach ($entries as $entry) {
            if ($entry === '.') {
                continue;
            }

            if ($entry === '..') {
                continue;
            }

            self::copy($source . '/' . $entry, $target . '/' . $entry, $followLinks, $ancestors);
        }
    }

    private static function delete(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        $entries = scandir($path);

        if ($entries !== false) {
            foreach ($entries as $entry) {
                if ($entry === '.') {
                    continue;
                }

                if ($entry === '..') {
                    continue;
                }

                self::delete($path . '/' . $entry);
            }
        }

        rmdir($path);
    }
}

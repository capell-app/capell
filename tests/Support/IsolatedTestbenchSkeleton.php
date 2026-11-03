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
 */
final class IsolatedTestbenchSkeleton
{
    private const string OwnerMarker = '.capell-skeleton-owner';

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

    /**
     * Resolve the base path this process should boot the Laravel application from.
     *
     * Serial and parallel processes both need isolation from other worktrees and runs.
     */
    public static function basePath(): string
    {
        if (self::$preparedPath !== null) {
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

        return self::$preparedPath = $targetPath;
    }

    /**
     * Remove skeletons left by processes that exited without their shutdown cleanup
     * (killed or crashed). Only directories untouched for longer than any plausible
     * run are removed, so a concurrent suite's live skeletons survive.
     */
    public static function removeStaleSkeletons(string $directory, ?string $keep = null, int $staleAfterSeconds = 21600): int
    {
        $entries = is_dir($directory) ? scandir($directory) : false;

        if ($entries === false) {
            return 0;
        }

        $removed = 0;
        // Plain Carbon, not the Date facade: this runs before the application exists.
        $cutoff = CarbonImmutable::now()->getTimestamp() - $staleAfterSeconds;

        foreach ($entries as $entry) {
            if ($entry === '.') {
                continue;
            }

            if ($entry === '..') {
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

            if (self::isStale($path, $cutoff)) {
                self::delete($path);
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * A skeleton records its owning PID. Its top-level mtime freezes once it is
     * prepared, so age alone would delete a long run's live skeleton; age only
     * decides for legacy skeletons that have no owner marker.
     */
    private static function isStale(string $path, int $cutoff): bool
    {
        $owner = @file_get_contents($path . '/' . self::OwnerMarker);

        if (is_string($owner) && preg_match('/\A[1-9]\d*\z/', trim($owner)) === 1) {
            return ! self::processIsAlive((int) trim($owner));
        }

        $modifiedAt = filemtime($path);

        return $modifiedAt !== false && $modifiedAt < $cutoff;
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

        return $root . DIRECTORY_SEPARATOR . $token;
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

    private static function prepare(string $sourcePath, string $targetPath): void
    {
        self::delete($targetPath);

        throw_if(! is_dir($targetPath) && ! mkdir($targetPath, 0o777, true) && ! is_dir($targetPath), RuntimeException::class, sprintf('Unable to create the isolated Testbench skeleton at [%s].', $targetPath));

        file_put_contents($targetPath . '/' . self::OwnerMarker, (string) getmypid());

        $entries = scandir($sourcePath);

        throw_if($entries === false, RuntimeException::class, sprintf('Unable to read the Testbench skeleton at [%s].', $sourcePath));

        foreach ($entries as $entry) {
            if ($entry === '.') {
                continue;
            }

            if ($entry === '..') {
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
        throw_if(! symlink(dirname(__DIR__, 2) . '/vendor', $targetPath . '/vendor'), RuntimeException::class, sprintf('Unable to link the checkout vendor at [%s].', $targetPath));

        // Re-point the storage link at this copy's storage rather than the shared skeleton's.
        if (is_dir($targetPath . '/public') && ! file_exists($targetPath . '/public/storage') && ! is_link($targetPath . '/public/storage')) {
            symlink($targetPath . '/storage/app/public', $targetPath . '/public/storage');
        }
    }

    private static function copy(string $source, string $target): void
    {
        // Symlinks in the skeleton (public/storage) point back at the shared skeleton, which would
        // re-introduce cross-process sharing. They are recreated against the copy instead.
        if (is_link($source)) {
            return;
        }

        if (! is_dir($source)) {
            copy($source, $target);

            return;
        }

        throw_if(! is_dir($target) && ! mkdir($target, 0o777, true) && ! is_dir($target), RuntimeException::class, sprintf('Unable to create the directory [%s].', $target));

        $entries = scandir($source);

        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.') {
                continue;
            }

            if ($entry === '..') {
                continue;
            }

            self::copy($source . '/' . $entry, $target . '/' . $entry);
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

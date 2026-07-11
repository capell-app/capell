<?php

declare(strict_types=1);

namespace Capell\Tests\Support;

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
 * Each process boots from tests/.pest/testbench-skeletons/<token>, a fresh copy of the skeleton.
 * Everything is copied — crucially app/ and bootstrap/cache/ — except storage/, which is recreated
 * as an empty directory tree because the shared one accumulates gigabytes of test artefacts. The
 * copy contains no symlinks: TailwindAssetsGenerator (and friends) resolve realpath() and reject
 * paths that escape the project.
 */
final class IsolatedTestbenchSkeleton
{
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
     * Returns the shared skeleton when the process is not part of a parallel run — a single
     * process cannot race itself.
     */
    public static function basePath(): string
    {
        if (self::$preparedPath !== null) {
            return self::$preparedPath;
        }

        $sourcePath = default_skeleton_path();

        throw_if($sourcePath === false, RuntimeException::class, 'Unable to resolve the Testbench skeleton path.');

        $token = self::token();

        if ($token === null) {
            return self::$preparedPath = $sourcePath;
        }

        // The directory name keeps the word "testbench": application code guards such as
        // ClearCachesAction::shouldSkipOptimizeClearForTestbench() recognise a Testbench skeleton
        // from the bootstrap path.
        $targetPath = dirname(__DIR__) . '/.pest/testbench-skeletons/' . $token;

        self::prepare($sourcePath, $targetPath);

        return self::$preparedPath = $targetPath;
    }

    /**
     * The parallel process identifier.
     *
     * scripts/run-pest-shards.php sets TEST_TOKEN=shard-N; Paratest (used by the
     * `pest --parallel` runner behind composer test:all) sets TEST_TOKEN=N. Both are covered.
     */
    private static function token(): ?string
    {
        $token = getenv('TEST_TOKEN');

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

            self::copy($sourcePath . '/' . $entry, $targetPath . '/' . $entry);
        }

        foreach (self::SCAFFOLDED_DIRECTORIES as $directory) {
            $path = $targetPath . '/' . $directory;

            throw_if(! is_dir($path) && ! mkdir($path, 0o777, true) && ! is_dir($path), RuntimeException::class, sprintf('Unable to create the directory [%s].', $path));
        }

        // Re-point the storage link at this copy's storage rather than the shared skeleton's.
        if (is_dir($targetPath . '/public')) {
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

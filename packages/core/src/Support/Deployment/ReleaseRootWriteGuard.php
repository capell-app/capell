<?php

declare(strict_types=1);

namespace Capell\Core\Support\Deployment;

use InvalidArgumentException;
use RuntimeException;

final class ReleaseRootWriteGuard
{
    private const string MUTABLE_MODE = 'mutable';

    /**
     * @param  list<string>  $relativePaths
     */
    public function assertWritable(
        string $operation,
        array $relativePaths,
        ?string $releaseRoot = null,
        bool $requiresServerSideTooling = false,
    ): void {
        $root = rtrim($releaseRoot ?? base_path(), DIRECTORY_SEPARATOR);
        $mode = config('capell.release_root_mode', self::MUTABLE_MODE);

        if ($requiresServerSideTooling && config('capell.server_side_tooling', false) !== true) {
            throw new RuntimeException(sprintf(
                '%s is blocked because CAPELL_SERVER_SIDE_TOOLING is disabled. '
                . 'Install the extension while building the next release, or explicitly enable '
                . 'server-side tooling for a directly addressed mutable deployment.',
                $operation,
            ));
        }

        if (! is_string($mode) || $mode !== self::MUTABLE_MODE) {
            throw new RuntimeException(sprintf(
                '%s is blocked because CAPELL_RELEASE_ROOT_MODE is %s. '
                . 'Runtime release-root writes require a directly addressed mutable build root; '
                . 'run this operation while building the next release instead.',
                $operation,
                is_scalar($mode) ? (string) $mode : 'invalid',
            ));
        }

        if ($root === '' || ! str_starts_with($root, DIRECTORY_SEPARATOR)) {
            throw new RuntimeException(sprintf(
                '%s is blocked because the application release root is not an absolute path: %s.',
                $operation,
                $root === '' ? '[empty]' : $root,
            ));
        }

        $symlink = $this->firstSymlinkComponent($root);

        if ($symlink !== null) {
            throw new RuntimeException(sprintf(
                '%s is blocked because the application release root traverses the symlink %s. '
                . 'Writing through an atomic current-release symlink can modify an old release; '
                . 'run this operation while building the next release instead.',
                $operation,
                $symlink,
            ));
        }

        foreach ($relativePaths as $relativePath) {
            $this->assertRelativePath($relativePath);

            $path = $root . DIRECTORY_SEPARATOR . $relativePath;
            $writablePath = $this->nearestExistingPath($path, $root);

            if ($writablePath !== null && is_writable($writablePath)) {
                continue;
            }

            throw new RuntimeException(sprintf(
                '%s is blocked because release-root path %s is not writable by the current PHP process. '
                . 'Keep the deployed release immutable and run this operation while building the next release.',
                $operation,
                $path,
            ));
        }
    }

    private function firstSymlinkComponent(string $path): ?string
    {
        $current = DIRECTORY_SEPARATOR;

        foreach (explode(DIRECTORY_SEPARATOR, ltrim($path, DIRECTORY_SEPARATOR)) as $component) {
            if ($component === '') {
                continue;
            }

            $current = rtrim($current, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $component;

            if (is_link($current)) {
                return $current;
            }
        }

        return null;
    }

    private function assertRelativePath(string $relativePath): void
    {
        if (
            $relativePath === ''
            || str_starts_with($relativePath, DIRECTORY_SEPARATOR)
            || in_array('..', explode(DIRECTORY_SEPARATOR, $relativePath), true)
        ) {
            throw new InvalidArgumentException(sprintf(
                'Release-root write paths must be non-empty relative paths without parent traversal: %s.',
                $relativePath === '' ? '[empty]' : $relativePath,
            ));
        }
    }

    private function nearestExistingPath(string $path, string $root): ?string
    {
        $candidate = $path;

        while (! file_exists($candidate) && ! is_link($candidate)) {
            if ($candidate === $root) {
                return null;
            }

            $parent = dirname($candidate);

            if ($parent === $candidate || ! str_starts_with($parent, $root)) {
                return null;
            }

            $candidate = $parent;
        }

        return $candidate;
    }
}

<?php

declare(strict_types=1);

namespace Capell\Core\Support\Deployment;

use Capell\Core\Enums\Deployment\ReleaseRootWriteRefusal;
use Capell\Core\Support\Filesystem\AbsolutePath;
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
        $refusal = $this->evaluate($operation, $relativePaths, $releaseRoot, $requiresServerSideTooling);

        if ($refusal === null) {
            return;
        }

        throw new RuntimeException($refusal['message']);
    }

    /**
     * Report why the guard would refuse the operation, as a value.
     *
     * Readiness reporting has to describe the host before anybody commits to an
     * operation, so no host condition is reported as an exception here. A
     * malformed $relativePaths entry is a caller bug rather than a host fact and
     * still raises InvalidArgumentException, exactly as assertWritable does.
     *
     *
     * @param  list<string>  $relativePaths
     *
     * @throws InvalidArgumentException when a relative path is empty, absolute,
     *                                  or contains parent traversal
     */
    public function check(
        string $operation,
        array $relativePaths,
        ?string $releaseRoot = null,
        bool $requiresServerSideTooling = false,
    ): ?string {
        return $this->evaluate($operation, $relativePaths, $releaseRoot, $requiresServerSideTooling)['message'] ?? null;
    }

    /**
     * The refusal as a typed reason, with the same contract as check().
     *
     * @param  list<string>  $relativePaths
     *
     * @throws InvalidArgumentException when a relative path is empty, absolute,
     *                                  or contains parent traversal
     */
    public function refusalReason(
        string $operation,
        array $relativePaths,
        ?string $releaseRoot = null,
        bool $requiresServerSideTooling = false,
    ): ?ReleaseRootWriteRefusal {
        return $this->evaluate($operation, $relativePaths, $releaseRoot, $requiresServerSideTooling)['reason'] ?? null;
    }

    /**
     * @param  list<string>  $relativePaths
     * @return array{reason: ReleaseRootWriteRefusal, message: string}|null
     */
    private function evaluate(
        string $operation,
        array $relativePaths,
        ?string $releaseRoot,
        bool $requiresServerSideTooling,
    ): ?array {
        $configuredRoot = $releaseRoot ?? base_path();
        // A backslash is only a trailing separator where it is a separator at
        // all. Trimming it on a unix host would silently retarget a directory
        // genuinely named `foo\` to `foo`.
        $root = rtrim($configuredRoot, AbsolutePath::hasWindowsSeparators($configuredRoot) ? '\\/' : '/');
        $mode = config('capell.release_root_mode', self::MUTABLE_MODE);

        if ($requiresServerSideTooling && config('capell.server_side_tooling', false) !== true) {
            return $this->refusal(ReleaseRootWriteRefusal::ServerSideToolingDisabled, sprintf(
                '%s is blocked because CAPELL_SERVER_SIDE_TOOLING is disabled. '
                . 'Install the extension while building the next release, or explicitly enable '
                . 'server-side tooling for a directly addressed mutable deployment.',
                $operation,
            ));
        }

        if (! is_string($mode) || $mode !== self::MUTABLE_MODE) {
            return $this->refusal(ReleaseRootWriteRefusal::ReleaseRootNotMutable, sprintf(
                '%s is blocked because CAPELL_RELEASE_ROOT_MODE is %s. '
                . 'Runtime release-root writes require a directly addressed mutable build root; '
                . 'run this operation while building the next release instead.',
                $operation,
                is_scalar($mode) ? (string) $mode : 'invalid',
            ));
        }

        $rootPrefix = AbsolutePath::rootPrefix($root);

        if ($root === '' || $rootPrefix === null) {
            return $this->refusal(ReleaseRootWriteRefusal::ReleaseRootNotAbsolute, sprintf(
                '%s is blocked because the application release root is not an absolute path: %s.',
                $operation,
                $root === '' ? '[empty]' : $root,
            ));
        }

        $symlink = $this->firstSymlinkComponent($root, $rootPrefix);

        if ($symlink !== null) {
            return $this->refusal(ReleaseRootWriteRefusal::ReleaseRootTraversesSymlink, sprintf(
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

            return $this->refusal(ReleaseRootWriteRefusal::ReleaseRootPathNotWritable, sprintf(
                '%s is blocked because release-root path %s is not writable by the current PHP process. '
                . 'Keep the deployed release immutable and run this operation while building the next release.',
                $operation,
                $path,
            ));
        }

        return null;
    }

    /**
     * @return array{reason: ReleaseRootWriteRefusal, message: string}
     */
    private function refusal(ReleaseRootWriteRefusal $reason, string $message): array
    {
        return ['reason' => $reason, 'message' => $message];
    }

    /**
     * Walk the release root one component at a time, so an atomic current-release
     * symlink is found wherever it sits rather than only at the end of the path.
     *
     * The components are rejoined with the separator the root itself is anchored
     * to: composing `C:\inetpub` with DIRECTORY_SEPARATOR on a unix host would
     * produce a path that never matches anything, and the walk would silently
     * inspect nothing.
     */
    private function firstSymlinkComponent(string $path, string $rootPrefix): ?string
    {
        $separator = str_contains($rootPrefix, '\\') ? '\\' : '/';
        $current = $rootPrefix;

        foreach ($this->pathComponents(substr($path, strlen($rootPrefix)), AbsolutePath::hasWindowsSeparators($path)) as $component) {
            $current = str_ends_with($current, $separator)
                ? $current . $component
                : $current . $separator . $component;

            if (is_link($current)) {
                return $current;
            }
        }

        return null;
    }

    /**
     * Relative write paths are split on both separators on every host. A caller
     * passes literals, so nothing legitimate contains a backslash, and reading
     * one as a separator can only reject more paths than before — never fewer.
     */
    private function assertRelativePath(string $relativePath): void
    {
        if (
            $relativePath === ''
            || AbsolutePath::is($relativePath)
            || str_starts_with($relativePath, '\\')
            || in_array('..', $this->pathComponents($relativePath, true), true)
        ) {
            throw new InvalidArgumentException(sprintf(
                'Release-root write paths must be non-empty relative paths without parent traversal: %s.',
                $relativePath === '' ? '[empty]' : $relativePath,
            ));
        }
    }

    /**
     * @return list<string>
     */
    private function pathComponents(string $path, bool $windowsSeparators): array
    {
        $normalized = $windowsSeparators ? str_replace('\\', '/', $path) : $path;
        $separator = $windowsSeparators ? '/' : DIRECTORY_SEPARATOR;

        return array_values(array_filter(
            explode($separator, $normalized),
            static fn (string $component): bool => $component !== '',
        ));
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

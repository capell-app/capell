<?php

declare(strict_types=1);

namespace Capell\Core\Enums\Deployment;

/**
 * Why a release-root write guard would refuse.
 *
 * Callers that only need to report on the host — rather than perform the write —
 * have to tell a deliberately immutable deployment apart from a broken one. The
 * first is a supported hosting shape that routes work through the build; the
 * second needs an operator to fix something.
 */
enum ReleaseRootWriteRefusal: string
{
    case ServerSideToolingDisabled = 'server_side_tooling_disabled';

    case ReleaseRootNotMutable = 'release_root_not_mutable';

    case ReleaseRootNotAbsolute = 'release_root_not_absolute';

    case ReleaseRootTraversesSymlink = 'release_root_traverses_symlink';

    case ReleaseRootPathNotWritable = 'release_root_path_not_writable';

    /**
     * True when the refusal reflects a deliberate deployment shape rather than a
     * misconfiguration. An atomic-symlink release root is the canonical example:
     * the deployment is correct, it just cannot be written to at runtime.
     */
    public function isByDesign(): bool
    {
        return match ($this) {
            self::ServerSideToolingDisabled,
            self::ReleaseRootNotMutable,
            self::ReleaseRootTraversesSymlink => true,
            self::ReleaseRootNotAbsolute,
            self::ReleaseRootPathNotWritable => false,
        };
    }
}

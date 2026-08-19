<?php

declare(strict_types=1);

namespace Capell\Frontend\Enums;

/**
 * Why a static error page did not resolve.
 *
 * `ResolveStaticErrorPageAction::handle()` returns `?string`, so every failure
 * looks identical to its caller. These codes name the exact gate that rejected
 * the request (or the manifest entry), which is what turns a "no static error
 * page was served" investigation from elimination into a single log line.
 */
enum StaticErrorPageResolutionReason: string
{
    /** No StaticErrorPageStore implementation is bound in the container. */
    case StoreUnbound = 'store_unbound';

    /** The manifest exists but contains no entries at all. */
    case ManifestEmpty = 'manifest_empty';

    /** The entry is for a different scheme (http vs https). */
    case SchemeMismatch = 'scheme_mismatch';

    /**
     * The entry is for a different host. The request host is
     * checkout-dependent locally, so this is the most common surprise.
     */
    case DomainMismatch = 'domain_mismatch';

    /** The entry is for a different HTTP status. */
    case StatusMismatch = 'status_mismatch';

    /** The entry's path prefix does not cover the request path. */
    case PathMismatch = 'path_mismatch';

    /** The entry matched but the store could not turn its file into a path. */
    case FileUnresolved = 'file_unresolved';

    /** The entry matched but the resolved file is not present on disk. */
    case FileMissing = 'file_missing';

    /** The entry matched and the file exists, but it could not be read. */
    case FileUnreadable = 'file_unreadable';

    /**
     * Ordering used to report the single most informative failure when several
     * entries were rejected for different reasons. A path mismatch means the
     * scheme, domain and status all matched, so it is more useful than a bare
     * scheme mismatch elsewhere in the manifest.
     */
    public function specificity(): int
    {
        return match ($this) {
            self::StoreUnbound => 0,
            self::ManifestEmpty => 1,
            self::SchemeMismatch => 2,
            self::DomainMismatch => 3,
            self::StatusMismatch => 4,
            self::PathMismatch => 5,
            self::FileUnresolved => 6,
            self::FileMissing => 7,
            self::FileUnreadable => 8,
        };
    }
}

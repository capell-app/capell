<?php

declare(strict_types=1);

namespace Capell\Core\Support\Filesystem;

/**
 * Absoluteness of a filesystem path, on every host Capell runs on.
 *
 * `str_starts_with($path, DIRECTORY_SEPARATOR)` answers this correctly only on
 * a unix host: on Windows it misses `C:/inetpub/capell`, and on unix it treats
 * every Windows path as relative. Anything that has to decide whether a path is
 * already anchored to a filesystem root belongs here rather than growing its own
 * regex.
 *
 * This is deliberately about filesystem paths only. A URL path legitimately
 * starts with `/` and has nothing to do with filesystem absoluteness.
 */
final class AbsolutePath
{
    /**
     * A Windows drive-letter root: a letter, a colon, and a separator. `C:foo`
     * is drive-relative rather than absolute, so the separator is required.
     */
    private const string DRIVE_ROOT_PATTERN = '/^[A-Za-z]:[\\\\\/]/';

    public static function is(string $path): bool
    {
        return self::rootPrefix($path) !== null;
    }

    /**
     * The filesystem root the path is anchored to — `/` for a unix path, `C:\`
     * or `C:/` for a Windows drive-letter path, `\\` for a UNC share — or null
     * when the path is relative.
     *
     * A single leading backslash is deliberately not a root. On unix it is an
     * ordinary filename character, and on Windows `\packages` is relative to
     * whichever drive happens to be current, so it does not identify a root
     * either. Both hosts therefore report it as relative.
     */
    public static function rootPrefix(string $path): ?string
    {
        if (preg_match(self::DRIVE_ROOT_PATTERN, $path) === 1) {
            return substr($path, 0, 3);
        }

        if (str_starts_with($path, '\\\\')) {
            return '\\\\';
        }

        return str_starts_with($path, '/') ? '/' : null;
    }

    /**
     * Whether a backslash has to be read as a directory separator in this path.
     *
     * True for every path on a Windows host, and for a Windows-rooted path
     * anywhere. A unix path keeps unix rules, where a backslash is an ordinary
     * character in a filename and splitting on it would misread the path.
     */
    public static function hasWindowsSeparators(string $path): bool
    {
        return DIRECTORY_SEPARATOR === '\\'
            || str_starts_with($path, '\\\\')
            || preg_match(self::DRIVE_ROOT_PATTERN, $path) === 1;
    }
}

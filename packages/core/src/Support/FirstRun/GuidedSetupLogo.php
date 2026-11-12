<?php

declare(strict_types=1);

namespace Capell\Core\Support\FirstRun;

use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The logo a person uploads in guided setup. It is stored privately under one directory of the
 * local disk and only ever read from there: a path is accepted only when it resolves to a PNG or
 * SVG file inside that directory, so a path sent by a browser can never reach another file.
 */
final class GuidedSetupLogo
{
    public const string DISK = 'local';

    public const string DIRECTORY = 'guided-setup';

    /** @var list<string> */
    private const array MIME_TYPES = ['image/png', 'image/svg+xml'];

    /** The file's absolute path when the stored path is a logo setup may use, otherwise null. */
    public static function path(?string $stored): ?string
    {
        if (! is_string($stored) || $stored === '' || str_contains($stored, "\0")) {
            return null;
        }

        try {
            $directory = realpath(Storage::disk(self::DISK)->path(self::DIRECTORY));
            $file = realpath(Storage::disk(self::DISK)->path(ltrim($stored, '/')));
        } catch (Throwable) {
            // The disk refuses a path that climbs out of it.
            return null;
        }

        if ($directory === false || $file === false || ! is_file($file) || ! str_starts_with($file, $directory . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return in_array(mime_content_type($file), self::MIME_TYPES, true) ? $file : null;
    }

    /** Removes a stored logo nobody will use, such as one left by setup that did not finish. */
    public static function discard(?string $stored): void
    {
        $path = self::path($stored);

        if ($path !== null) {
            @unlink($path);
        }
    }
}

<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Error;

use Throwable;

/**
 * The one place the error-page manifest paths are decided.
 *
 * `capell-frontend.error_pages.manifest_directory` relocates both manifests.
 * Production leaves it unset and keeps them in `storage/framework`; a test
 * suite running several processes against one storage directory points each
 * process at its own directory, because a fixed shared path lets one process
 * delete or rewrite the manifest another process is rendering from.
 *
 * An unreadable configuration falls back to the default directory rather
 * than throwing: the DB-free fallback reader uses this while rendering an
 * error page, possibly before configuration is usable.
 */
final class ErrorPageManifestLocation
{
    public const string CONFIG_KEY = 'capell-frontend.error_pages.manifest_directory';

    public static function manifest(): string
    {
        return self::directory() . DIRECTORY_SEPARATOR . 'capell-error-pages.json';
    }

    public static function fallback(): string
    {
        return self::directory() . DIRECTORY_SEPARATOR . 'capell-error-pages-fallback.json';
    }

    private static function directory(): string
    {
        try {
            $configured = config(self::CONFIG_KEY);

            if (is_string($configured) && $configured !== '') {
                return rtrim($configured, '/\\');
            }

            return storage_path('framework');
        } catch (Throwable) {
            return storage_path('framework');
        }
    }
}

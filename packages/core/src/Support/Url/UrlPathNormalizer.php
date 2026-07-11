<?php

declare(strict_types=1);

namespace Capell\Core\Support\Url;

final class UrlPathNormalizer
{
    /**
     * Strip a trailing `/index.php` from a path (front-controller artefact).
     *
     * NOTE (by design): this only removes `index.php` when it is the final
     * segment. Inputs like `/index.php/` or `/index.php/foo` are returned
     * untouched on purpose — a non-trailing `index.php` is PATH_INFO routing,
     * not a front-controller suffix, and is not this helper's concern.
     */
    public static function stripIndexPhp(string $path): string
    {
        if ($path === '/index.php' || str_ends_with($path, '/index.php')) {
            $stripped = rtrim(substr($path, 0, -10), '/');

            return $stripped === '' ? '/' : $stripped . '/';
        }

        return $path;
    }

    public static function joinPrefix(string $prefix, string $path): string
    {
        $prefix = rtrim($prefix, '/');

        if ($prefix === '') {
            return $path;
        }

        if ($path === '' || $path === '/') {
            return $prefix;
        }

        return $prefix . '/' . ltrim($path, '/');
    }

    public static function stripPrefix(string $path, string $prefix): string
    {
        $prefix = rtrim($prefix, '/');

        if ($prefix === '' || ! str_starts_with($path, $prefix . '/')) {
            return $path === $prefix ? '/' : $path;
        }

        return substr($path, strlen($prefix));
    }
}

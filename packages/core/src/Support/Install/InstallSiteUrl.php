<?php

declare(strict_types=1);

namespace Capell\Core\Support\Install;

use InvalidArgumentException;

final class InstallSiteUrl
{
    /** Removes URL credentials, query strings and fragments from saved settings. */
    public static function publicUrl(string $url): string
    {
        $parts = parse_url($url);
        throw_if(! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || self::validationError($url) !== null, InvalidArgumentException::class, 'A valid HTTP or HTTPS URL is required.');

        return strtolower($parts['scheme']) . '://' . $parts['host']
            . (isset($parts['port']) ? ':' . $parts['port'] : '') . ($parts['path'] ?? '');
    }

    public static function validationError(string $url): ?string
    {
        $parts = parse_url($url);
        if (filter_var($url, FILTER_VALIDATE_URL) === false || ! is_array($parts)
            || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            return (string) __('capell-core::install.site.url_invalid');
        }

        return null;
    }
}

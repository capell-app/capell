<?php

declare(strict_types=1);

namespace Capell\Core\Support\Url;

/**
 * The floor for any link whose value came from outside this installation (a remote
 * service response, a stored snapshot of one). It accepts only an absolute https URL
 * with a host and no embedded credentials, so a `javascript:`, `data:`, plain `http:`
 * or scheme-relative value never reaches an `href`. Callers that also need a host
 * allow-list apply their own policy first and use this as the last line of defence.
 */
final class ExternalHttpsUrl
{
    public static function sanitise(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $url = trim($value);

        if ($url === '' || preg_match('/[\x00-\x20\x7F\\\\]/', $url) === 1) {
            return null;
        }

        $parts = parse_url($url);

        if ($parts === false) {
            return null;
        }

        $scheme = $parts['scheme'] ?? null;
        $host = $parts['host'] ?? null;

        if (! is_string($scheme) || strtolower($scheme) !== 'https' || ! is_string($host) || $host === '') {
            return null;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        return $url;
    }
}

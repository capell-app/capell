<?php

declare(strict_types=1);

namespace Capell\Core\Support\Security;

use Capell\Core\Support\Url\RedirectTargetPolicy;

final class PublicUrlSanitizer
{
    public static function sanitize(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $url = trim($value);

        if ($url === '' || str_contains($url, "\0")) {
            return null;
        }

        $lowerUrl = strtolower($url);

        if (str_starts_with($lowerUrl, '#') || str_starts_with($lowerUrl, 'mailto:') || str_starts_with($lowerUrl, 'tel:')) {
            return preg_match('/[\x00-\x1F\x7F]/', $url) === 1 ? null : $url;
        }

        // Paths and web addresses follow the shared redirect target rules, which refuse
        // protocol-relative forms (`//`, `/\`, `\\`), control characters browsers strip
        // ("/\t/evil.example") and authorities that hide another host behind credentials
        // or a backslash.
        return resolve(RedirectTargetPolicy::class)->validate($url)->target;
    }
}

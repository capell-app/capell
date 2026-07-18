<?php

declare(strict_types=1);

namespace Capell\Core\Support\Security;

use Illuminate\Support\Str;

final class PublicUrlSanitizer
{
    /**
     * @var array<int, string>
     */
    private const array ALLOWED_PREFIXES = [
        '/',
        '#',
        'https://',
        'http://',
        'mailto:',
    ];

    public static function sanitize(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $url = trim($value);

        if ($url === '' || str_contains($url, "\0")) {
            return null;
        }

        $lowerUrl = Str::lower($url);

        if (Str::startsWith($lowerUrl, '//')) {
            return null;
        }

        return Str::startsWith($lowerUrl, self::ALLOWED_PREFIXES) ? $url : null;
    }
}
